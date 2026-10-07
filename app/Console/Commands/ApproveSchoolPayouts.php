<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSchool;
use App\Models\SchoolAuditEvent;
use App\Support\RecordsSchoolAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H3: mark a school verified so its payouts may be sent, and lift any bank-change
 * hold. Run only after the school has been verified out of band (who registered
 * it, that they represent the school, that the payout account belongs to it).
 *
 * Nothing is transferred by this command. Held payouts stay `pending`; the
 * hourly `payouts:run --dispatch` queues them on its next run.
 */
class ApproveSchoolPayouts extends Command
{
    use ResolvesSchool;

    protected $signature = 'schools:approve-payouts
        {school : The school id or slug}
        {--note= : How the school was verified (recorded on the audit event)}
        {--keep-hold : Approve the school but leave an active bank-change hold in place}';

    protected $description = 'Verify a school for payouts (and lift its bank-change hold)';

    public function handle(RecordsSchoolAudit $audit): int
    {
        $school = $this->resolveSchool((string) $this->argument('school'));
        if (! $school) {
            return self::FAILURE;
        }

        $note = trim((string) $this->option('note'));
        if ($note === '') {
            $this->error('Give --note="…" saying how the school was verified; it is recorded on the audit event.');

            return self::FAILURE;
        }

        $before = [
            'payouts_approved_at' => $school->payouts_approved_at?->toIso8601String(),
            'payout_hold_until' => $school->payout_hold_until?->toIso8601String(),
        ];

        DB::transaction(function () use ($school, $audit, $note, $before) {
            $school->forceFill([
                'payouts_approved_at' => $school->payouts_approved_at ?? now(),
                'payout_hold_until' => $this->option('keep-hold') ? $school->payout_hold_until : null,
            ])->save();

            $audit->record($school, SchoolAuditEvent::ACTION_PAYOUTS_APPROVED, 'school', $school->id, [
                'payouts_approved_at' => ['from' => $before['payouts_approved_at'], 'to' => $school->payouts_approved_at?->toIso8601String()],
                'payout_hold_until' => ['from' => $before['payout_hold_until'], 'to' => $school->payout_hold_until?->toIso8601String()],
                'note' => ['from' => null, 'to' => mb_substr($note, 0, 500)],
            ], SchoolAuditEvent::ACTOR_ARTISAN);
        });

        Log::warning('OPERATOR: school approved for payouts', ['school_id' => $school->id, 'note' => $note]);

        $reason = $school->payoutBlockReason();
        $this->info("{$school->name} ({$school->slug}) is approved for payouts.");
        $this->line($reason === null
            ? 'Eligible now. Pending payouts are sent by the next `payouts:run --dispatch`.'
            : "Still held: {$reason}.");

        return self::SUCCESS;
    }
}
