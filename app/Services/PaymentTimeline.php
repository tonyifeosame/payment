<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Transaction;

/**
 * The payment → payout story shown on the transaction and payout detail pages:
 * one implementation, so the two can never tell different stories.
 */
class PaymentTimeline
{
    /**
     * The payment → payout story as a list of steps, built only from stored
     * timestamps and statuses. A step without a recorded time carries null rather
     * than a guess. Copy is deliberately plain: the payout's last_error, provider
     * response and transfer identifiers are internal and never surface here.
     *
     * @return list<array{label:string, note:string, at:\Illuminate\Support\Carbon|null, state:'done'|'current'|'attention'|'upcoming'}>
     */
    public function forTransaction(Transaction $transaction): array
    {
        $step = fn (string $label, string $note, $at, string $state) => compact('label', 'note', 'at', 'state');

        $steps = [$step('Payment started', 'The parent opened checkout for this fee.', $transaction->created_at, 'done')];

        switch ($transaction->status) {
            case Transaction::STATUS_SUCCESS:
                $steps[] = $step('Payment confirmed', 'The payment provider confirmed the money was received.', $transaction->paid_at, 'done');
                break;
            case 'pending':
                $steps[] = $step('Awaiting confirmation', 'The payment has not been confirmed yet. It usually completes within minutes; abandoned checkouts stay here.', null, 'current');

                return $steps;
            case 'mismatch':
                $steps[] = $step('Payment under review', 'The amount confirmed did not match this fee, so the platform is checking it before anything else happens.', null, 'attention');

                return $steps;
            default:
                $steps[] = $step('Payment not completed', 'The provider did not confirm this payment. Nothing was collected from the parent.', null, 'attention');

                return $steps;
        }

        $payout = $transaction->payout;
        if (! $payout) {
            $steps[] = $step('Payout', 'No payout has been recorded for this payment yet.', null, 'upcoming');

            return $steps;
        }

        $steps[] = $step('Payout queued', 'Your share was recorded and is waiting to be sent to your bank account.', $payout->created_at, $payout->status === Payout::PENDING ? 'current' : 'done');

        if ($payout->initiated_at || in_array($payout->status, [Payout::INITIATING, Payout::PROCESSING], true)) {
            $steps[] = $step('Payout sent to bank', 'The transfer to your bank account has been requested.', $payout->initiated_at, $payout->status === Payout::INITIATING ? 'current' : 'done');
        }

        switch ($payout->status) {
            case Payout::PROCESSING:
                $steps[] = $step('Processing at bank', 'The bank is finalising the transfer. No action is needed from you.', null, 'current');
                break;
            case Payout::SUCCESS:
                $steps[] = $step('Payout paid', 'The bank confirmed the money reached your account.', $payout->completed_at, 'done');
                break;
            case Payout::FAILED:
                $steps[] = $step('Payout failed', 'The transfer did not complete. The platform will retry it; if it stays in this state, contact support.', $payout->completed_at, 'attention');
                break;
            case Payout::REVERSED:
                $steps[] = $step('Payout reversed', 'The bank returned this transfer. Contact support if you were expecting it.', $payout->completed_at, 'attention');
                break;
            case Payout::NEEDS_REVIEW:
                $steps[] = $step('Payout under review', 'The platform is checking this payout before any money moves. Contact support if it stays in this state.', null, 'attention');
                break;
        }

        return $steps;
    }

    /**
     * Plain-language state of a payout for the school, grouped the way
     * SchoolDashboardService::payoutSummary() groups statuses.
     *
     * @return array{group:string|null, headline:string, note:string}
     */
    public function payoutState(?Payout $payout, bool $paymentConfirmed = true): array
    {
        $group = match ($payout?->status) {
            Payout::PENDING => 'waiting',
            Payout::INITIATING, Payout::PROCESSING => 'processing',
            Payout::SUCCESS => 'paid',
            Payout::FAILED, Payout::REVERSED, Payout::NEEDS_REVIEW => 'attention',
            default => null,
        };

        $headline = match ($group) {
            'waiting' => 'Awaiting payout',
            'processing' => 'Being processed',
            'paid' => 'Paid out',
            'attention' => 'Needs attention',
            default => $paymentConfirmed ? 'No payout recorded yet' : 'No payout',
        };

        $note = match ($group) {
            'waiting' => 'Your share is queued to be sent to your bank account.',
            'processing' => 'The transfer to your bank account is in progress. No action is needed from you.',
            'paid' => 'The bank confirmed the money reached your account'.($payout->completed_at ? ' on '.$payout->completed_at->format('d M Y 	 H:i') : '').'.',
            'attention' => match ($payout->status) {
                Payout::FAILED => 'The transfer did not complete. The platform will retry it; if it stays in this state, contact support quoting the payout reference.',
                Payout::REVERSED => 'The bank returned this transfer. Contact support quoting the payout reference if you were expecting it.',
                default => 'The platform is checking this payout before any money moves. Contact support quoting the payout reference if it stays in this state.',
            },
            default => $paymentConfirmed
                ? 'A payout is created when a confirmed payment settles. If this stays empty, contact support.'
                : 'A payout is only created once the payment is confirmed.',
        };

        return compact('group', 'headline', 'note');
    }
}
