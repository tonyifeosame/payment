<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSchool;
use App\Models\SchoolAuditEvent;
use App\Support\RecordsSchoolAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * H3: withdraw a school's payout approval. Parents can still pay and payments
 * still settle; new transfers wait until the school is approved again. Payouts
 * already handed to Paystack (initiating / processing) are not recalled.
 */
class SuspendSchoolPayouts extends Command
{
    use ResolvesSchool;

    protected $signature = 'schools:suspend-payouts
        {school : The school id or slug}
        {--note= : Why payouts are suspended (recorded on the audit event)}';

    protected $description = 'Withdraw a school\'s payout approval; payouts wait until it is approved again';

    public function handle(RecordsSchoolAudit $audit): int
    {
        $school = $this->resolveSchool((string) $this->argument('school'));
        if (! $school) {
            return self::FAILURE;
        }

        $note = trim((string) $this->option('note'));
        if ($note === '') {
            $this->error('Give --note="…" saying why; it is recorded on the audit event.');

            return self::FAILURE;
        }

        $was = $school->payouts_approved_at?->toIso8601String();

        DB::transaction(function () use ($school, $audit, $note, $was) {
            $school->forceFill(['payouts_approved_at' => null])->save();

            $audit->record($school, SchoolAuditEvent::ACTION_PAYOUTS_SUSPENDED, 'school', $school->id, [
                'payouts_approved_at' => ['from' => $was, 'to' => null],
                'note' => ['from' => null, 'to' => mb_substr($note, 0, 500)],
            ], SchoolAuditEvent::ACTOR_ARTISAN);
        });

        Log::warning('OPERATOR: school payouts suspended', ['school_id' => $school->id, 'note' => $note]);

        $this->info("Payouts for {$school->name} ({$school->slug}) are suspended. Payments are still accepted.");

        return self::SUCCESS;
    }
}
