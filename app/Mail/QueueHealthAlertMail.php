<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * The notice `jobs:check` sends when queued work needs an operator (M5).
 *
 * Deliberately a Mailable rather than Mail::raw(): this is the one signal that
 * something has gone wrong, so it has to be assertable in a test. Mail::raw()
 * is a no-op under Mail::fake(), which would have left the alert path silently
 * unverified — the exact class of blind spot this finding is about.
 *
 * Plain text, and never queued: it is sent from the cron container to report
 * that the queue itself is unhealthy, so it must not depend on the queue.
 */
class QueueHealthAlertMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string>  $problems  keyed reasons the check failed
     * @param  array<string, mixed>  $metrics  the raw counts behind them
     */
    public function __construct(public array $problems, public array $metrics) {}

    public function build()
    {
        return $this
            ->subject('['.config('app.name').'] Queue health needs attention')
            ->text('emails.queue_health_alert');
    }
}
