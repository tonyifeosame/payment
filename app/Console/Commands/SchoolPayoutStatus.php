<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Models\School;
use Illuminate\Console\Command;

/**
 * H3: which schools are waiting for payout verification or inside a bank-change
 * hold, and how much is waiting for each. With --all, every school.
 */
class SchoolPayoutStatus extends Command
{
    protected $signature = 'schools:payout-status {--all : List every school, not only those whose payouts are held}';

    protected $description = 'List schools whose payouts are held (awaiting verification or a bank-change hold)';

    public function handle(): int
    {
        $rows = School::query()->orderBy('id')->get()
            ->filter(fn (School $s) => $this->option('all') || ! $s->canReceivePayouts())
            ->map(function (School $s) {
                $waiting = Payout::where('school_id', $s->id)->where('status', Payout::PENDING);

                return [
                    $s->id,
                    $s->slug,
                    $s->name,
                    $s->account_name ?? '—',
                    $s->created_at?->toDateString(),
                    $s->payoutBlockReason() ?? 'eligible',
                    (clone $waiting)->count(),
                    number_format((float) (clone $waiting)->sum('amount'), 2),
                ];
            });

        if ($rows->isEmpty()) {
            $this->info('No school has held payouts.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug', 'Name', 'Account name', 'Registered', 'Payouts', 'Pending', 'Pending NGN'], $rows->values()->all());

        return self::SUCCESS;
    }
}
