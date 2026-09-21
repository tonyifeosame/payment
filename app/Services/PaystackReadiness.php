<?php

namespace App\Services;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-production readiness checks for the Paystack integration (B2).
 *
 * Answers one question for an operator: what does this code verify, what is
 * missing, and what must still be confirmed by hand in Paystack and Render?
 * Every check is READ-ONLY — configuration, route table, schema, row counts and
 * two read-only Paystack lookups (bank directory, balance). Nothing here charges,
 * transfers, creates payouts, sends mail or writes a row. No secret value ever
 * leaves this class: a key is reported as configured/missing and live/test.
 *
 * Statuses:
 *   PASS    verified by the application
 *   WARN    works, but worth a look before go-live (never blocks on its own)
 *   FAIL    a required application-side prerequisite is missing
 *   MANUAL  cannot be verified from the application; confirm in the Paystack
 *           dashboard or Render — listed so it is never assumed to be done
 *
 * Production rules (HTTPS APP_URL, live key, real mailer, real queue) apply when
 * the app runs as `production` or when the caller asks for them explicitly.
 */
class PaystackReadiness
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const MANUAL = 'MANUAL';

    public const WEBHOOK_PATH = '/paystack/webhook';

    public const CALLBACK_PATH = '/payment/callback';

    /** Payout recovery / reconciliation commands an operator relies on. */
    public const OPERATOR_COMMANDS = ['payouts:run', 'payouts:retry', 'payouts:release', 'payouts:lookup'];

    /** @var array<int, array{id: string, group: string, status: string, label: string, detail: string}> */
    private array $checks = [];

    public function __construct(private PaystackService $paystack) {}

    /**
     * @return array<int, array{id: string, group: string, status: string, label: string, detail: string}>
     */
    public function run(bool $productionRules, bool $callApi = true): array
    {
        $this->checks = [];

        $this->configuration($productionRules);
        $this->endpoints();
        $this->delivery($productionRules);
        $this->transfers();
        if ($callApi) {
            $this->api($productionRules);
        } else {
            $this->add('api.skipped', 'Paystack API', self::WARN, 'API checks skipped', 'Run without --no-api to verify the key against Paystack.');
        }
        $this->manual();

        return $this->checks;
    }

    /** @return array{PASS: int, WARN: int, FAIL: int, MANUAL: int} */
    public static function summarise(array $checks): array
    {
        $summary = [self::PASS => 0, self::WARN => 0, self::FAIL => 0, self::MANUAL => 0];
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return $summary;
    }

    /** Which Paystack environment a key belongs to, without revealing it. */
    public static function classifyKey(?string $key, string $kind = 'sk'): string
    {
        $key = (string) $key;
        if ($key === '') {
            return 'missing';
        }
        if (str_starts_with($key, $kind.'_live_')) {
            return 'live';
        }
        if (str_starts_with($key, $kind.'_test_')) {
            return 'test';
        }

        return 'unrecognised';
    }

    // -----------------------------------------------------------------------

    private function configuration(bool $production): void
    {
        $g = 'Application configuration';

        $this->add('app.env', $g, self::PASS, 'Environment', app()->environment().($production ? ' — production rules applied' : ' — production rules NOT applied (use --production to apply them)'));

        $this->add('app.key', $g, config('app.key') ? self::PASS : self::FAIL, 'APP_KEY',
            config('app.key') ? 'configured (signs receipt links and sessions; must be identical on web, worker and cron)' : 'missing — signed receipt links and sessions cannot work');

        $debug = (bool) config('app.debug');
        $this->add('app.debug', $g, $debug && $production ? self::FAIL : ($debug ? self::WARN : self::PASS), 'APP_DEBUG',
            $debug ? 'enabled — must be false in production (error pages would expose configuration)' : 'disabled');

        $url = (string) config('app.url');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($url === '' || $host === '') {
            $this->add('app.url', $g, $production ? self::FAIL : self::WARN, 'APP_URL', 'missing — webhook, callback and receipt links would be built from an unknown host');
        } elseif (in_array($host, ['localhost', '127.0.0.1'], true) && $production) {
            $this->add('app.url', $g, self::FAIL, 'APP_URL', "{$url} — points at localhost; Paystack cannot reach the webhook or return the payer");
        } elseif ($scheme !== 'https') {
            $this->add('app.url', $g, $production ? self::FAIL : self::WARN, 'APP_URL', "{$url} — not HTTPS; Paystack webhooks and callbacks and the secure session cookie require an https URL in production");
        } else {
            $this->add('app.url', $g, self::PASS, 'APP_URL', $url);
        }

        $secret = self::classifyKey(config('services.paystack.secret_key'), 'sk');
        $this->add('paystack.secret_key', $g, match (true) {
            $secret === 'missing' => self::FAIL,
            $secret === 'live' => $production ? self::PASS : self::WARN,
            $secret === 'test' => $production ? self::FAIL : self::PASS,
            default => self::WARN,
        }, 'PAYSTACK_SECRET_KEY', match ($secret) {
            'missing' => 'missing — no charge can be verified, no webhook accepted, no transfer sent',
            'live' => 'configured — LIVE key'.($production ? '' : ' outside production: real money would move'),
            'test' => 'configured — TEST key'.($production ? ': real payments will not be accepted in production' : ''),
            default => 'configured — unrecognised format (expected sk_live_… or sk_test_…)',
        });

        $public = self::classifyKey(config('services.paystack.public_key'), 'pk');
        $this->add('paystack.public_key', $g, $public === 'missing' ? self::PASS : ($public === $secret || $public === 'unrecognised' ? self::PASS : self::WARN), 'PAYSTACK_PUBLIC_KEY', match ($public) {
            'missing' => 'not set — optional: checkout is a server-side redirect, no application code reads it',
            'unrecognised' => 'configured — unrecognised format (not read by application code)',
            default => 'configured — '.strtoupper($public).' key'.($public !== $secret && $secret !== 'missing' ? ' (does not match the secret key\'s environment)' : ''),
        });

        $apiUrl = rtrim((string) config('services.paystack.payment_url'), '/');
        $this->add('paystack.api_url', $g, $apiUrl === 'https://api.paystack.co' ? self::PASS : self::WARN, 'PAYSTACK_PAYMENT_URL',
            $apiUrl === 'https://api.paystack.co' ? $apiUrl : ($apiUrl ?: 'empty').' — not Paystack\'s production API base');

        $this->add('paystack.webhook_secret', $g, $secret === 'missing' ? self::FAIL : self::PASS, 'Webhook signing secret',
            'no separate webhook secret: signatures are verified as HMAC-SHA512 over the raw body with PAYSTACK_SECRET_KEY'.($secret === 'missing' ? ' — which is missing' : ''));

        $tz = (string) config('fees.reporting_timezone');
        $this->add('reporting.timezone', $g, in_array($tz, timezone_identifiers_list(), true) ? self::PASS : self::FAIL, 'REPORTING_TIMEZONE', $tz ?: 'missing');
    }

    private function endpoints(): void
    {
        $g = 'Webhook & callback endpoints';
        $base = rtrim((string) config('app.url'), '/');

        $webhook = Route::getRoutes()->getByName('paystack.webhook');
        $ok = $webhook && in_array('POST', $webhook->methods(), true) && '/'.$webhook->uri() === self::WEBHOOK_PATH;
        $this->add('webhook.route', $g, $ok ? self::PASS : self::FAIL, 'Webhook endpoint',
            $ok ? 'POST '.$base.self::WEBHOOK_PATH.' — register this URL in the Paystack dashboard; requires the x-paystack-signature header (HMAC-SHA512); CSRF-exempt by registration outside the web group'
                : 'route paystack.webhook is not registered as POST '.self::WEBHOOK_PATH);

        $callback = Route::getRoutes()->getByName('payment.callback');
        $ok = $callback && in_array('GET', $callback->methods(), true) && '/'.$callback->uri() === self::CALLBACK_PATH;
        $this->add('callback.route', $g, $ok ? self::PASS : self::FAIL, 'Browser callback',
            $ok ? 'GET '.$base.self::CALLBACK_PATH.' — sent to Paystack as callback_url on every initialize; settlement re-verifies server-side' : 'route payment.callback is not registered as GET '.self::CALLBACK_PATH);
    }

    private function delivery(bool $production): void
    {
        $g = 'Receipts, queue & worker';

        $mailer = (string) config('mail.default');
        $fake = in_array($mailer, ['log', 'array', ''], true);
        if ($fake) {
            $this->add('mail.mailer', $g, $production ? self::FAIL : self::WARN, 'MAIL_MAILER', "{$mailer} — receipts are not delivered to parents");
        } elseif ($mailer === 'smtp' && ! config('mail.mailers.smtp.host')) {
            $this->add('mail.mailer', $g, $production ? self::FAIL : self::WARN, 'MAIL_MAILER', 'smtp — MAIL_HOST is empty');
        } else {
            $this->add('mail.mailer', $g, self::PASS, 'MAIL_MAILER', $mailer.($mailer === 'smtp' ? ' via '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port') : ''));
        }

        $from = (string) config('mail.from.address');
        $placeholder = $from === '' || str_ends_with($from, '@example.com') || str_ends_with($from, '@example.test');
        $this->add('mail.from', $g, $placeholder ? ($production ? self::FAIL : self::WARN) : self::PASS, 'MAIL_FROM_ADDRESS',
            $placeholder ? ($from ?: 'missing').' — placeholder sender; receipts and password resets would come from it' : $from.' ('.config('mail.from.name').')');

        $queue = (string) config('queue.default');
        if ($queue === 'sync') {
            $this->add('queue.connection', $g, $production ? self::FAIL : self::WARN, 'QUEUE_CONNECTION', 'sync — the Paystack transfer would run inside the payer\'s HTTP request; use database (Render worker drains it)');
        } elseif ($queue === 'null') {
            $this->add('queue.connection', $g, self::FAIL, 'QUEUE_CONNECTION', 'null — receipts and payout jobs would be discarded');
        } else {
            $this->add('queue.connection', $g, self::PASS, 'QUEUE_CONNECTION', $queue.' — a worker (`queue:work '.$queue.'`) must be running; on Render that is the laravel-queue-worker service');
        }

        $cache = (string) config('cache.default');
        $shared = in_array($cache, ['database', 'redis', 'memcached', 'dynamodb'], true);
        $this->add('cache.store', $g, $shared ? self::PASS : self::WARN, 'CACHE_STORE (job uniqueness lock)',
            $cache.($shared ? ' — shared by web, worker and cron' : ' — per-container: InitiateSchoolPayout\'s unique-job lock is not shared between services (the database claim still prevents double transfers)'));
    }

    private function transfers(): void
    {
        $g = 'Transfers (application side)';

        $tables = ['payouts', 'payout_recovery_events', 'jobs', 'cache_locks'];
        $missing = array_values(array_filter($tables, fn ($t) => ! Schema::hasTable($t)));
        $this->add('db.schema', $g, $missing === [] ? self::PASS : self::FAIL, 'Database schema',
            $missing === [] ? 'payouts, payout_recovery_events, jobs and cache_locks tables present' : 'missing tables: '.implode(', ', $missing).' — run `php artisan migrate --force`');

        $withBank = School::whereNotNull('bank_code')->whereNotNull('account_number')->whereNotNull('account_name')->count();
        $withRecipient = School::whereNotNull('paystack_recipient_code')->count();
        $this->add('transfers.recipients', $g, $withBank > 0 ? self::PASS : self::WARN, 'School payout accounts',
            "{$withBank} school(s) with verified bank details, {$withRecipient} with a Paystack transfer recipient (created automatically on the first payout)".($withBank === 0 ? ' — no school can be paid yet' : ''));

        $this->add('transfers.integration', $g, class_exists(InitiateSchoolPayout::class) && method_exists($this->paystack, 'initiateTransfer') && method_exists($this->paystack, 'fetchTransfer') ? self::PASS : self::FAIL,
            'Transfer integration', 'InitiateSchoolPayout (immediate payout per settled payment, source: Paystack balance, reference = idempotency key) and transfer lookup are present');

        $registered = array_keys(Artisan::all());
        $absent = array_values(array_diff(self::OPERATOR_COMMANDS, $registered));
        $this->add('transfers.operator_commands', $g, $absent === [] ? self::PASS : self::FAIL, 'Reconciliation & recovery commands',
            $absent === [] ? implode(', ', self::OPERATOR_COMMANDS).' registered (see docs/production/paystack-runbook.md)' : 'missing: '.implode(', ', $absent));

        $review = Payout::where('status', Payout::NEEDS_REVIEW)->count();
        $failed = Payout::where('status', Payout::FAILED)->count();
        $stale = app(PayoutService::class)->staleInitiating()->count();
        $processing = Payout::where('status', Payout::PROCESSING)->count();
        $attention = [];
        if ($review > 0) {
            $attention[] = "{$review} needs_review (payouts:release after investigation)";
        }
        if ($failed > 0) {
            $attention[] = "{$failed} failed (payouts:retry once the cause is fixed)";
        }
        if ($stale > 0) {
            $attention[] = "{$stale} initiating for over ".PayoutService::STALE_INITIATING_MINUTES.' min (payouts:lookup --stale)';
        }
        $this->add('payouts.ledger', $g, $attention === [] ? self::PASS : self::WARN, 'Payout ledger',
            $attention === [] ? 'no payouts need attention'.($processing > 0 ? "; {$processing} processing (waiting for the Paystack transfer webhook)" : '') : implode('; ', $attention));
    }

    private function api(bool $production): void
    {
        $g = 'Paystack API (read-only)';

        if (self::classifyKey(config('services.paystack.secret_key')) === 'missing') {
            $this->add('api.auth', $g, self::FAIL, 'Authentication', 'skipped — no secret key configured');

            return;
        }

        $banks = $this->paystack->listBanks();
        if ($banks['ok'] ?? false) {
            $this->add('api.auth', $g, self::PASS, 'Authentication', 'GET /bank succeeded — the key is accepted and Paystack is reachable ('.count($banks['banks']).' banks listed)');
        } else {
            $reason = $banks['reason'] ?? 'unavailable';
            $this->add('api.auth', $g, $reason === 'config' ? self::FAIL : self::WARN, 'Authentication',
                $reason === 'config' ? 'Paystack rejected the configured key (HTTP 401/403) — check PAYSTACK_SECRET_KEY' : 'Paystack could not be reached or answered with an error ('.$this->safeMessage($banks['message'] ?? null).') — try again; if it persists, check outbound network from Render');

            return;
        }

        $balance = $this->paystack->fetchBalance();
        if (! ($balance['ok'] ?? false)) {
            $this->add('api.balance', $g, self::WARN, 'Transfer balance', 'GET /balance did not succeed ('.$this->safeMessage($balance['message'] ?? null).') — confirm the balance in the Paystack dashboard');

            return;
        }

        $ngn = null;
        foreach ($balance['balances'] ?? [] as $row) {
            if ($row['currency'] === 'NGN') {
                $ngn = $row['balance'];
            }
        }
        if ($ngn === null) {
            $this->add('api.balance', $g, self::WARN, 'Transfer balance', 'no NGN balance reported — transfers are paid from the NGN balance');
        } elseif ($ngn <= 0) {
            $this->add('api.balance', $g, self::WARN, 'Transfer balance', 'NGN 0.00 — every transfer would be rejected for insufficient balance until settlements land in the balance or it is topped up');
        } else {
            $this->add('api.balance', $g, self::PASS, 'Transfer balance', 'NGN '.number_format($ngn, 2).' available'.($production ? '' : ' (test balance)'));
        }
    }

    private function manual(): void
    {
        $g = 'Paystack dashboard / Render — verify by hand';
        $url = rtrim((string) config('app.url'), '/').self::WEBHOOK_PATH;

        $items = [
            ['manual.live_mode', 'Live mode is intentional', 'the configured key is the LIVE secret key of the correct Paystack business, and the dashboard is switched to Live when checking the items below'],
            ['manual.transfers_enabled', 'Transfers enabled on the live account', 'Paystack enables transfers only for a registered business; without it every InitiateSchoolPayout is rejected'],
            ['manual.balance_funding', 'Balance is funded for transfers', 'transfers draw on the Paystack balance: set settlement destination to the balance, or keep it topped up; card settlements go to the bank account by default and leave the balance at 0'],
            ['manual.transfer_otp', 'Transfer OTP / approval disabled', 'unattended server-side transfers cannot complete an OTP; with OTP on, payouts sit in processing forever'],
            ['manual.webhook_registered', 'Webhook URL registered (live)', 'Settings → API Keys & Webhooks (Live) → Webhook URL = '.$url],
            ['manual.webhook_reachable', 'Webhook reachable over HTTPS', 'Paystack must reach '.$url.' from the internet; on Render the web service (not the worker) serves it and must not be sleeping'],
            ['manual.settlement_bank', 'Settlement / bank configuration', 'the business\'s own settlement account and schedule are correct in the dashboard'],
            ['manual.render_services', 'Render services running', 'laravel-app (web), laravel-queue-worker (worker) and laravel-payout-reconciliation (cron, hourly payouts:run --dispatch) are deployed and healthy'],
            ['manual.render_env_parity', 'APP_KEY / APP_URL identical on all three services', 'the worker signs receipt links the web service validates; the cron and worker share the same database queue and cache locks'],
        ];

        foreach ($items as [$id, $label, $detail]) {
            $this->add($id, $g, self::MANUAL, $label, $detail);
        }
    }

    private function add(string $id, string $group, string $status, string $label, string $detail): void
    {
        $this->checks[] = ['id' => $id, 'group' => $group, 'status' => $status, 'label' => $label, 'detail' => $detail];
    }

    /** Provider messages are short and non-secret, but never echo more than a line. */
    private function safeMessage(?string $message): string
    {
        $message = trim((string) $message);

        return $message === '' ? 'no message' : mb_substr(preg_replace('/\s+/', ' ', $message), 0, 120);
    }
}
