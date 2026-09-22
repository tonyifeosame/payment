<?php

namespace App\Support;

use App\Models\School;
use App\Models\SchoolAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes the audit rows for M7.
 *
 * Every caller invokes this from INSIDE the transaction that performs the
 * mutation, so a rolled-back change leaves no event behind and an event never
 * exists without its change. The recorder therefore opens no transaction of its
 * own — a nested one would create a savepoint that could commit independently
 * of the outer rollback, which is precisely the guarantee being bought here.
 * Writes are synchronous for the same reason: a queued write could outlive a
 * rollback, and could be lost while the change it describes survives.
 *
 * It uses whatever connection the model is on, so it follows the mutation.
 *
 * On honesty of attribution: a school has one shared credential, so `actor` is
 * a ROLE and `actor_session` is a hash of the session id. Two actions with the
 * same hash came from the same signed-in session; two different hashes came
 * from different sessions. Neither says which member of staff was at the
 * keyboard, and nothing here should be read as if it did.
 */
final class RecordsSchoolAudit
{
    /** Values that must never reach the audit table, whatever a caller passes. */
    private const REDACTED_KEYS = [
        'admin_password', 'password', 'password_confirmation', 'current_password',
        'paystack_recipient_code', 'account_number', 'bank_code',
        'secret', 'secret_key', 'token', 'api_key',
    ];

    /**
     * Record one action.
     *
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes  already-diffed fields
     */
    public function record(
        School $school,
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?array $changes = null,
        string $actor = SchoolAuditEvent::ACTOR_SCHOOL_ADMIN,
        ?Request $request = null,
    ): SchoolAuditEvent {
        return SchoolAuditEvent::on($school->getConnectionName())->create([
            'school_id' => $school->id,
            'actor' => $actor,
            'actor_session' => $this->sessionHash($request),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId !== null ? (int) $subjectId : null,
            'changes' => $changes !== null ? $this->redact($changes) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * The fields that actually changed, as {field: {from, to}}.
     *
     * Only keys present in $keys are considered, and a key whose value is
     * unchanged is left out entirely — an audit row should say what moved, not
     * restate the whole record.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $keys
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after, array $keys): array
    {
        $changes = [];

        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            // Loose-but-typed comparison: a decimal column read back as "50000.00"
            // and a form value of 50000 are the same fee price, not a change.
            if ($this->isSame($from, $to)) {
                continue;
            }

            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /** The last four digits of an account number, which is all that may be stored. */
    public function lastFour(?string $accountNumber): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $accountNumber);

        return $digits === '' || $digits === null ? null : substr($digits, -4);
    }

    /**
     * SHA-256 of the current session id, or null when there is no session.
     *
     * The raw id is a live credential and is never stored. The hash is stable
     * for the life of a session and differs between sessions, which is all the
     * audit needs to group one sitting's actions together.
     */
    private function sessionHash(?Request $request): ?string
    {
        $request ??= request();

        if (! $request || ! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->getId();

        return $id ? hash('sha256', $id) : null;
    }

    /**
     * Final guard before anything is written.
     *
     * Callers are expected to pass only safe fields — the bank change hands over
     * last-four values, never full numbers — but a secret must not reach this
     * table because one caller was careless, so the key list is enforced here
     * too. Values are also length-capped: an audit row is a record, not a store.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        $safe = [];

        foreach ($changes as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $safe[$key] = '[redacted]';

                continue;
            }

            $safe[$key] = is_array($value)
                ? array_map($this->cap(...), $value)
                : $this->cap($value);
        }

        return $safe;
    }

    private function cap(mixed $value): mixed
    {
        return is_string($value) ? Str::limit($value, 500, '') : $value;
    }

    private function isSame(mixed $from, mixed $to): bool
    {
        if ($from === null || $to === null) {
            return $from === $to;
        }

        if (is_numeric($from) && is_numeric($to)) {
            return (float) $from === (float) $to;
        }

        return $from === $to;
    }
}
