<?php

namespace App\Console\Commands\Concerns;

use App\Models\Payout;

/**
 * Shared plumbing for the payout recovery commands (payouts:retry, payouts:release,
 * payouts:lookup): resolving a payout by OUR reference and describing it without
 * ever printing anything sensitive. Output names the payout by reference, the
 * school by id/name and the destination by the last four digits only — never a
 * full account number, a recipient code, a provider payload or an API key.
 */
trait ResolvesPayoutsForRecovery
{
    /** Find a payout by its PO- reference, or explain why not. */
    protected function payoutByReference(string $reference): ?Payout
    {
        $reference = trim($reference);

        if ($reference === '') {
            $this->error('A payout reference is required (e.g. PO-8b1d…).');

            return null;
        }

        $payout = Payout::with(['school', 'transaction'])->where('reference', $reference)->first();

        if (! $payout) {
            $this->error("No payout with reference {$reference}.");

            return null;
        }

        return $payout;
    }

    /** One safe line describing where a payout stands. */
    protected function describe(Payout $payout): string
    {
        $school = $payout->school;
        $destination = $school && $school->account_number
            ? ($school->bank ?: 'bank').' ····'.substr((string) $school->account_number, -4)
            : 'no payout account on file';

        return sprintf(
            '%s — school #%d %s — NGN %s — %s — attempts %d — payment %s — to %s',
            $payout->reference,
            $payout->school_id,
            $school?->name ?? '(deleted)',
            number_format((float) $payout->amount, 2),
            $payout->status,
            (int) $payout->attempts,
            $payout->transaction?->reference ?? '-',
            $destination
        );
    }

    /** Batch summary in the fixed processed / changed / skipped / failed shape. */
    protected function summary(int $processed, int $changed, int $skipped, int $failed, string $changedLabel = 'changed'): void
    {
        $this->newLine();
        $this->line("processed: {$processed}");
        $this->line("{$changedLabel}: {$changed}");
        $this->line("skipped: {$skipped}");
        $this->line("failed: {$failed}");
    }
}
