<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\School;
use App\Models\Payout;
use App\Services\PaystackService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RunPayouts extends Command
{
    protected $signature = 'payouts:run {--date=} {--dry-run}';

    protected $description = 'Aggregate yesterday\'s successful transactions per school and transfer to schools at 9AM';

    public function handle(PaystackService $paystack)
    {
        $dateStr = $this->option('date');
        $date = $dateStr ? Carbon::parse($dateStr)->startOfDay() : now()->subDay()->startOfDay();
        $start = $date->copy();
        $end = $date->copy()->endOfDay();

        $this->info("Running payouts for date: {$date->toDateString()} ({$start} - {$end})");

        // Sum base amounts per school from successful transactions in the window
        $rows = Transaction::select('school_id', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('school_id')
            ->get()
            ->pluck('cnt', 'school_id');

        if ($rows->isEmpty()) {
            $this->info('No successful transactions found for the period.');
            return Command::SUCCESS;
        }

        foreach ($rows as $schoolId => $count) {
            $school = School::find($schoolId);
            if (!$school) { continue; }

            // Compute total base amount for this school
            $transactions = Transaction::where('status', 'success')
                ->where('school_id', $schoolId)
                ->whereBetween('created_at', [$start, $end])
                ->get();

            $totalBase = 0.0;
            foreach ($transactions as $t) {
                $meta = $t->meta_data;
                if (is_string($meta)) { $meta = json_decode($meta, true); }
                $base = is_array($meta) ? ($meta['base_amount'] ?? $t->amount) : $t->amount;
                $totalBase += (float) $base;
            }
            $totalBase = round($totalBase, 2);

            // Create payout record first
            $payout = Payout::create([
                'school_id' => $schoolId,
                'amount' => $totalBase,
                'currency' => 'NGN',
                'payout_date' => $date->toDateString(),
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'pending',
            ]);

            $this->info("School {$school->name} - count={$count} base_total=NGN {$totalBase}");

            if ($this->option('dry-run')) {
                $this->line('Dry run: skipping actual transfer.');
                continue;
            }

            // Amount in Kobo
            $amountKobo = (int) round($totalBase * 100);
            if ($amountKobo <= 0) {
                $this->warn('Amount is zero or negative. Skipping transfer.');
                $payout->status = 'failed';
                $payout->response = ['message' => 'Zero amount'];
                $payout->save();
                continue;
            }

            $reason = 'Daily payout for ' . $date->toDateString();
            $result = $paystack->initiateTransferToSchool($school, $amountKobo, $reason);

            if (!($result['ok'] ?? false)) {
                $this->error('Transfer failed: ' . ($result['message'] ?? 'unknown'));
                $payout->status = 'failed';
                $payout->response = $result['response'] ?? $result;
                $payout->save();
                continue;
            }

            $resp = $result['response'] ?? [];
            $data = $resp['data'] ?? [];
            $payout->status = 'success';
            $payout->transfer_code = $data['transfer_code'] ?? null;
            $payout->transfer_id = isset($data['id']) ? (string) $data['id'] : null;
            $payout->response = $resp;
            $payout->save();
        }

        $this->info('Payouts completed.');
        return Command::SUCCESS;
    }
}
