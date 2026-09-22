# FEYRA — Paystack production runbook

Operator guide for taking FEYRA live with real Paystack payments and school
payouts, and for handling the situations that come up afterwards. It uses the
application's own terms: a **transaction** is a parent's payment, a **payout** is
the obligation (and transfer) that settles the school's share of one
transaction, and every command below is an Artisan command run on the web
service shell (Render → laravel-app → Shell) unless stated otherwise.

Before anything else, run the read-only readiness check and fix every `FAIL`:

```
php artisan paystack:check --production
php artisan paystack:check --production --json   # CI / ticket attachment
```

It prints `PASS` (verified by the code), `WARN` (look before go-live), `FAIL`
(a required application-side prerequisite is missing → exit code 1) and
`MANUAL` (things only you can confirm in the Paystack dashboard or Render — the
command never marks these as done). Exit code is 0 when nothing fails; `WARN`
and `MANUAL` do not change it. The command makes two read-only Paystack calls
(`GET /bank`, `GET /balance`) and never prints a key, an account number or a
raw provider payload.

---

## 1. Environment setup

All three Render services (`laravel-app` web, `laravel-queue-worker`,
`laravel-payout-reconciliation` cron) are built from the same image and read
the same variables. Set them in each service's dashboard — `render.yaml` marks
every secret `sync: false` on purpose, so nothing secret is in the repo.

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | enables HTTPS enforcement and production rules |
| `APP_DEBUG` | `false` | error pages must never show configuration |
| `APP_KEY` | one value, **identical on all three services**, never rotated | signs receipt links (worker signs, web validates) and encrypts sessions; a new key invalidates every receipt link ever emailed |
| `APP_URL` | `https://<your-host>` — identical on all three services | builds the webhook/callback URLs Paystack uses and every emailed link; must be HTTPS |
| `PAYSTACK_SECRET_KEY` | the **live** secret key (`sk_live_…`) | verifies charges, signs/validates webhooks, sends transfers; the only Paystack credential the code reads |
| `PAYSTACK_PUBLIC_KEY` | live public key (optional) | not read by any code today (checkout is a server-side redirect); set it for completeness |
| `PAYSTACK_PAYMENT_URL` | `https://api.paystack.co` | API base |
| `DB_CONNECTION` / `DATABASE_URL` | `pgsql` / the Render Postgres | one database shared by all services — it is also the queue and the lock store |
| `QUEUE_CONNECTION` | `database` | payout transfers and receipt emails are queued jobs; the worker service drains them |
| `CACHE_STORE` | `database` | `InitiateSchoolPayout` is a unique job; the lock must be shared by web, worker and cron. The per-IP rate limiters (section 7) count in this store too, so the limit holds across every web instance |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` | `database` / `true` | admin sessions over HTTPS only |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | your SMTP provider | receipts (worker), password resets and bank-change notices (web) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | a real sender on your domain | receipts and password resets come from it; the contact form delivers to it |
| `REPORTING_TIMEZONE` | `Africa/Lagos` (default) | the clock every reporting surface quotes — see "Timezones" below. Leave it unset unless the school base moves: the default is correct and `paystack:check` fails on an invalid identifier |
| `PENDING_PAYMENT_EXPIRY_HOURS` | `24` (default) | how long a checkout may stay `pending` before the hourly cron verifies it with Paystack (section 5b); the answer, never the age, decides the outcome |
| `LOG_CHANNEL` | `stderr` | Render keeps stderr; the container filesystem does not survive a deploy |
| `OPERATIONS_ALERT_EMAIL` | optional; defaults to `MAIL_FROM_ADDRESS` | where the hourly `jobs:check` sends its notice (section 6a). The cron service carries the full `MAIL_*` set for this reason — without it `MAIL_MAILER` falls back to `log` and the alert would never leave the container |
| `OPERATIONS_ALERT_COOLDOWN_MINUTES` | `360` (default) | how often the same unhealthy state may re-alert |
| `HEALTH_FAILED_JOBS_THRESHOLD`, `HEALTH_QUEUE_DEPTH_THRESHOLD`, `HEALTH_STALLED_PAYOUTS_THRESHOLD`, `HEALTH_ATTENTION_PAYOUTS_THRESHOLD` | `1`, `100`, `1`, `1` (defaults) | the counts at which `jobs:check` reports unhealthy (`config/operations.php`) |
| `SKIP_MIGRATIONS` | `true` on worker and cron only | only the web service runs `migrate --force` at start |

Transfers need nothing beyond `PAYSTACK_SECRET_KEY`: the platform fee is
`config/fees.php` (`markup_percent`, 2.5 % by default), the school's share is
read from each transaction's stored breakdown, and the Paystack transfer
recipient for a school is created automatically on its first payout from the
bank details verified at registration or in Settings.

## 2. Paystack dashboard setup

Do these in the **Live** dashboard of the business whose live key is configured.

1. **Transfers capability** — Paystack enables transfers only for a registered
   business. Confirm "Transfers" is available; otherwise every payout is
   rejected as soon as the first payment settles.
2. **Settlement / balance** — transfers are paid from the **Paystack balance**
   (`source: balance` in `PaystackService::initiateTransfer`). Card collections
   settle to your bank account by default and leave the balance at ₦0, so either
   set the settlement destination to the balance or keep the balance topped up.
   `paystack:check` shows the current NGN balance.
3. **Transfer authorisation / OTP** — turn off OTP for API transfers. The worker
   runs unattended; a transfer waiting for an OTP is held as `processing` and
   never completes.
4. **Webhook** — Settings → API Keys & Webhooks → **Live** → Webhook URL:

   ```
   POST {APP_URL}/paystack/webhook
   ```

   There is no separate webhook secret: the endpoint verifies the
   `x-paystack-signature` header as HMAC-SHA512 of the raw body with
   `PAYSTACK_SECRET_KEY`, so the key in the dashboard and the key on Render must
   be the same live key. The endpoint is registered outside the web middleware
   group (no session, no CSRF) and answers `401` to any unsigned or mis-signed
   delivery.
5. **HTTPS reachability** — Paystack must reach the URL above from the internet.
   The **web** service serves it (not the worker); it must be on a paid plan
   that does not sleep, and `APP_URL` must be the public HTTPS host.
6. **Bank / settlement account** — confirm the business's own settlement account
   and schedule are what you expect.

## 3. Pre-launch checklist

Run `php artisan paystack:check --production` — every `FAIL` fixed, every
`MANUAL` item ticked by hand — then walk both flows in **test mode** first
(`DEMO.md` covers the local test-mode walkthrough) and once more in live mode
with the first production transaction (section 4).

**Collections**
- [ ] `https://<host>/pay/{school-slug}` renders the school's fees and finds an active student
- [ ] "Pay" redirects to Paystack's hosted checkout with the expected total (fee + service fee)
- [ ] `GET /payment/callback` returns the payer to the school page with a success message
- [ ] the `charge.success` webhook is delivered and acknowledged (`200`, body `{"status":"settled"}` or `already_settled`)
- [ ] the transaction shows `success` with `paid_at` in the school admin (Transactions)
- [ ] the receipt page and PDF download open from the emailed signed link
- [ ] the receipt email arrives from `MAIL_FROM_ADDRESS`

**Payouts**
- [ ] the school's bank account was verified (Settings → payout account resolves to the bank's account name)
- [ ] a `pending` payout appears for the settled transaction, amount = the school's fee share (not the gross)
- [ ] the worker picks the job up: payout moves to `processing` with a `transfer_code`
- [ ] exactly one transfer appears in the Paystack dashboard for the payout's `PO-…` reference
- [ ] the `transfer.success` webhook moves the payout to `success` (shown as "Paid" to the school)
- [ ] `php artisan payouts:run --dry-run` reports nothing missing
- [ ] `php artisan payouts:retry`, `payouts:release`, `payouts:lookup` are registered (`php artisan list payouts`)

## 4. First production transaction

Use one small real payment appropriate for the school and your account — a
genuine fee on a genuine active student, paid with a real card, coordinated
with the school so the payout is expected. Then verify, in order:

1. **Settlement** — Transactions shows the payment as `success`; the callback and
   the webhook both settled it exactly once (`transactions.paid_at` written once;
   webhook log shows `settled` then `already_settled` on any retry).
2. **Obligation** — one payout row exists for the transaction (`payouts.transaction_id` is unique).
3. **Amount** — `payouts.amount` equals the transaction's school share: the
   fee subtotal from its breakdown, never the gross the parent paid.
4. **Single transfer** — the payout went `pending → initiating → processing`
   once; the dashboard shows one transfer with the `PO-…` reference and the same
   kobo amount.
5. **Resolution** — the `transfer.success` webhook set `success` and
   `completed_at`; a repeated delivery changes nothing.
6. **Receipt** — web page and PDF show the school, student, admission number,
   session/term, fee, service fee, total charged, status, reference, date.
7. **Email** — the receipt email arrived with a working signed link.
8. **No duplicates** — one transaction, one payout, one transfer; the hourly
   cron (`payouts:run --dispatch`) reports nothing to reconcile.

If any step fails, stop onboarding and use sections 5–6; do not "fix" it in the
Paystack dashboard.

## 5. Payout recovery commands

All are read-and-write on **our** ledger only; none creates a transfer itself.
The transfer is always sent by the queued `InitiateSchoolPayout` job, which
claims the payout atomically (`pending → initiating`), sends our `PO-…`
reference as the idempotency key, and refuses any amount the payment does not
authorise. Every command writes an immutable row to `payout_recovery_events`
(payout, action, previous/new status, source `artisan`, amount for a release,
reason, result) — including refusals.

| Command | Use when | What it does |
|---|---|---|
| `php artisan payouts:retry {reference}` | a payout is `failed` and the cause is fixed (balance topped up, recipient/bank corrected) | re-validates `payouts.amount` against the payment, resets `failed → pending`, dispatches the job. Refuses every other state. A payout already `pending` is queued again (safe). |
| `php artisan payouts:retry --all-failed` | many payouts failed for one cause (typically insufficient balance) | the above for each `failed` payout independently; prints `processed / reset / skipped / failed` |
| `php artisan payouts:release {reference} --amount=50000 [--note="why"]` | a payout is `needs_review` and you have established the correct school share | sets the amount and moves `needs_review → pending`, then dispatches. The amount must be positive, at most two decimals, and **never above the school share** recorded for the payment (or, for a legacy payment with no trustworthy breakdown, never above what the parent paid). A payout with no transaction behind it cannot be released. |
| `php artisan payouts:lookup {reference}` | a payout is `initiating` (transfer outcome unknown) | asks Paystack for the transfer by our reference: found → its status is applied (amount- and currency-checked); not found (404) → released to `failed` for a retry; unknown → left `initiating`. Never re-sends. |
| `php artisan payouts:lookup --stale` | after a Paystack or network incident | the above for every `initiating` payout older than 10 minutes; recent ones are untouched |
| `php artisan payouts:run --dispatch` | the hourly cron, or by hand after a worker outage | records missing obligations for settled payments, re-queues `pending` payouts that were never dispatched, and looks up every payout `initiating` for over 10 minutes (same lookup-only path as `payouts:lookup --stale`); never sends a transfer |
| `php artisan payments:expire-pending [--limit=200] [--dry-run]` | the hourly cron (after `payouts:run`), or by hand | verifies every **payment** pending for over `PENDING_PAYMENT_EXPIRY_HOURS` with Paystack: success → settled (receipt + payout as usual), failed/reversed/abandoned/unknown reference → `failed`, still open or unreachable → left pending; oldest first, at most `--limit` per run; never a charge or transfer |

`payouts:release` and `payouts:retry` are operator overrides: they log at
warning/critical with the payout, school, amount and reason. Keep the ticket
reference in `--note`.

### 5b. Payment attempt lifecycle (H5)

A parent's checkout is a **transaction**:

| Local status | Meaning | How it gets there |
|---|---|---|
| `pending` | checkout created; Paystack has not confirmed anything | `initialize` (before the parent is even redirected) |
| `success` | Paystack verified the charge; amount and currency matched | callback, `charge.success` webhook, or the expiry pass discovering a late success — **terminal: nothing ever moves it back** |
| `failed` | Paystack gave a definitive non-success for this attempt | callback/webhook verify returning `failed` or `reversed`; or the expiry pass finding the checkout `abandoned` or the reference unknown after the window |
| `mismatch` | Paystack confirmed a charge whose amount/currency did not match | callback/webhook — a human investigates; never auto-resolved |

Paystack never sends a "charge failed" webhook for hosted checkout; failure is
learned only by asking (`GET /transaction/verify/{reference}`), which is why
a decline the parent sees on Paystack's page still shows here as `pending`
until the callback or the hourly pass verifies it. `abandoned` means the parent
has not completed checkout *yet* and can still do so, so it is only recorded as
failed once `PENDING_PAYMENT_EXPIRY_HOURS` has passed. A timed-out request, a
missing callback or a closed browser is never treated as proof of failure.

A `failed` row keeps its reference, amount and currency, and records
Paystack's status and short gateway message (`meta_data.failure`) for internal
diagnosis; admins see "Payment not completed" with the time it was observed —
never the provider payload, and never the service fee or gross. If Paystack
later reports that "failed" attempt as successful (the parent retried on the
same checkout), the next callback/webhook/expiry verification settles it
normally: the receipt is queued and the payout obligation recorded then, exactly
once. A failed attempt never creates a payout.

**Never:**
- re-send a transfer from the Paystack dashboard as a workaround — the
  application would not know about it and the payout would be paid twice once it
  is retried. Reconcile the payout here first (`payouts:lookup`, then `retry`).
- edit `payouts.amount` (or any payout row) directly in the database. The job
  refuses an amount above the payment's share and parks the payout as `failed`;
  the audit trail would show nothing.
- retry an `initiating` or `processing` payout. Resolve it with `payouts:lookup`
  (or wait for the transfer webhook) first; `payouts:retry` refuses these states.
- release a `needs_review` payout without establishing the amount from the
  transaction (Transactions → the payment's fee breakdown) and, if in doubt, the
  school. `needs_review` means the system could not separate the school's share
  from the platform fee, or Paystack reported a transfer that did not match.

## 6. Incident handling

**Webhook outage (Paystack cannot reach `/paystack/webhook`)**
Payments still settle through the browser callback when the parent returns;
those who do not return stay `pending` until the webhook is redelivered
(Paystack retries). Payout `transfer.*` events are also missed, so payouts sit
in `processing`. Restore reachability (web service up, HTTPS, URL registered),
then: the hourly cron looks up anything `initiating` for over 10 minutes on its
own; run `php artisan payouts:lookup --stale` yourself to do it immediately. For
`processing` payouts wait for Paystack's retry or use "Resend" on the event in
the dashboard's webhook log. Never re-initiate the transfer.

**Transfer stuck `initiating`**
The transfer request got no answer. The hourly cron (`payouts:run`) looks every
such payout up automatically once it is 10 minutes old; to act sooner run
`php artisan payouts:lookup {reference}` (or `--stale`). Found → resolved; 404 →
`failed`, then `payouts:retry` once the cause is known; still unknown → left
`initiating` and looked up again next hour. Do not touch the row.

**Transfer stuck `processing`**
Paystack accepted it and has not finalised it. Check the transfer in the
dashboard by `PO-…` reference: if it is waiting for OTP/approval, fix the
account setting (section 2.3) and approve that one; otherwise wait for the
`transfer.success` / `transfer.failed` webhook (or resend it from the dashboard
log). `payouts:lookup` only acts on `initiating`; `payouts:retry` refuses
`processing`.

**Failed transfer**
The payout is `failed` with the reason in the school's ledger detail
("Needs attention"). Typical causes: insufficient balance, recipient not
available (school has no verified bank details), Paystack rejection. Fix the
cause, then `php artisan payouts:retry {reference}` (or `--all-failed` after
a balance top-up). If Paystack refuses because the reference already exists,
look the transfer up in the dashboard before anything else.

**`needs_review`**
Either the payment has no trustworthy fee breakdown (legacy row) or Paystack
reported a transfer whose amount/currency/reference did not match. Investigate
the transaction and the dashboard transfer; when the correct school share is
established, `php artisan payouts:release {reference} --amount=… --note=…`.
Never release the gross.

**Duplicate or ambiguous provider response**
The state machine already tolerates replays: a repeated `charge.success` is
`already_settled`; a repeated `transfer.*` event is a no-op; a transfer
reported with the wrong amount is parked in `needs_review`, never marked paid.
If the dashboard shows two transfers for one `PO-…` reference, stop retries,
reconcile with Paystack support, and record the outcome with `payouts:release`
/ `payouts:retry` only after the ledger and the dashboard agree.

**Payment shows `pending` for a long time**
Either the parent never completed checkout (it becomes `failed`/"not completed"
after the expiry window) or Paystack's confirmation has not reached us. Run
`php artisan payments:expire-pending --dry-run` to see the candidates and
`php artisan payments:expire-pending` to verify them now; a charge Paystack
reports as successful is settled on the spot. Never mark a payment failed or
successful by hand.

**Worker outage**
Payments still settle (web service); receipts and transfers queue up in the
`jobs` table. Restart `laravel-queue-worker` in Render; the backlog drains in
order. Then `php artisan payouts:run --dispatch` re-queues any `pending`
payout whose job was lost. Check `php artisan queue:failed` for receipt jobs
that exhausted their retries and `queue:retry` them.

**Insufficient Paystack balance**
Transfers are rejected → payouts `failed` with Paystack's message. Fund the
balance (or fix the settlement destination), confirm with
`paystack:check`, then `php artisan payouts:retry --all-failed`.

**Recipient / bank problem**
"Recipient not available": the school has no verified bank details — the
school updates its payout account in Settings (password re-entry + Paystack
account resolution; the recipient code is cleared and re-created on the next
payout), then `payouts:retry`. A Paystack rejection naming the recipient
(closed account, name mismatch) is the same path. Payouts already
`initiating`/`processing` keep the recipient they were sent with.

## 6a. Queue health and operator alerts (M5)

`php artisan jobs:check` is a **read-only** report, run hourly by the cron after
the two reconciliation passes. It reports four things and exits non-zero when
any threshold in `config/operations.php` is met:

| Metric | Why it matters |
|---|---|
| rows in `failed_jobs` (+ age of the oldest) | work abandoned after its retries; `queue:failed` / `queue:retry` |
| rows in `jobs` | a backlog means `laravel-queue-worker` is not draining |
| payouts `pending` with **`attempts >= 1`** | **`payouts:run --dispatch` does NOT re-queue these** — it filters on `attempts = 0`. Only `payouts:retry` moves them |
| payouts `failed` / `needs_review` | deliberate dead ends; `payouts:lookup`, then `payouts:retry` or `payouts:release` |

**How you find out.** The check emails `OPERATIONS_ALERT_EMAIL` (falling back to
`MAIL_FROM_ADDRESS`) when unhealthy, at most once per
`OPERATIONS_ALERT_COOLDOWN_MINUTES` (default 360) so a persistent problem does
not fill the inbox. Mail is the primary signal because it is the delivery path
this application already proves in production.

Its non-zero exit is a **secondary** signal. Render does support a "cron job
execution fails" notification by email, but it is a **dashboard setting**
(Integrations → Notifications, or the service's own Settings → Notifications,
level "Only failure notifications") that cannot be declared in `render.yaml`,
and its default state is not documented. **If you want that second channel,
switch it on in the dashboard** — nothing in this repository can do it for you,
and nothing here assumes it is on.

`--json` gives the same report machine-readably; `--no-mail` reports and sets the
exit code without sending. The command writes nothing — it runs on the cron
container, which carries `SKIP_MIGRATIONS=true`.

Separately, **every** job that exhausts its retries now writes one
`Log::critical('Queued job failed permanently')` with the connection, queue, job
name, job id and attempt count (`Queue::failing` in `bootstrap/app.php`).
Previously only `InitiateSchoolPayout` did, so a receipt mailable that gave up
left nothing but a `failed_jobs` row. The payload is deliberately never logged —
it carries the payer's email and the serialized transaction.

## 6b. Timezones

Timestamps are **stored in UTC** (`app.timezone`) and always will be: the
columns carry no zone, `paid_at` is compared against Paystack's own instants,
and every query compares against `now()`. Nothing converts on the way in.

Conversion happens at the edge, in `App\Support\BusinessTime`, which reads
`REPORTING_TIMEZONE` (`Africa/Lagos` — WAT, UTC+1, no DST). Every reporting
surface goes through it: dashboard, transaction list and detail, student
payment history, payout ledger and detail, the payment timeline, the web, PDF
and email receipts, the CSV export, the categories list and the promotion
history. Date filters go through the same helper, so a `date_from` is the start
of that business day in the reporting zone, converted to storage time — the
list, the export and the filters all agree on which day a payment belongs to.

Before this (M3) only the dashboard converted, so a payment settled between
00:00 and 01:00 Lagos was dated one day on the dashboard and the previous day
on the ledger, the CSV and the parent's receipt.

Two things to know operationally:

- **Exports and receipts now read an hour later** than before for payments in
  that late-night window. The stored instant did not change. The CSV column is
  labelled `Date Paid (WAT)` and receipts print the zone next to the time, so an
  old file and a new one can be told apart rather than silently disagreeing.
- **`RunPayouts` is deliberately NOT converted.** Its `--day` and `--since`
  filters are operator scoping for money movement, not presentation, and they
  stay on UTC day boundaries. Changing them would change which payouts a manual
  run picks up. If you pass `--day`, you are naming a UTC day.

## 6c. Net reporting on legacy transactions (L9)

**What changed.** The dashboard's net tiles, its category breakdown and a
student's "total paid" summed `transactions.fee_amount` directly. The
transaction list, the CSV export, the receipts and the dashboard's own
recent-payments list read `Transaction::receiptBreakdown()` instead, which for a
payment whose metadata carries no usable `base_amount` answers **"the whole
charge"** — there is nothing to split by, so nothing was the platform's. The
aggregates now use the same fallback, `COALESCE(fee_amount, amount)`
(`Transaction::netAmountExpression()`).

**Which records are affected.** Only transactions where `fee_amount IS NULL`.
These predate the column, and the backfill migration
(`2026_09_12_000200_add_student_and_period_context_to_fees_and_transactions`)
could only populate a row whose `meta_data` contained a numeric `base_amount`;
it skipped the rest, which is why they are still null. Every payment taken since
writes `fee_amount` at checkout, so no new row can be in this state.

**What the numbers do.** For those rows only, the reported **net changes from
₦0 to the full transaction amount**. Net figures can therefore only go up, never
down. Gross totals, counts, the transaction list, the CSV, receipts, payouts and
every money movement are **unchanged** — this aligns the aggregates with the
interpretation the rest of the application already used. No transaction,
payment or payout record is modified, and there is no data migration.

**Before deploying, count what will move.** Read-only; run against production:

```sql
-- How many payments will start reporting a non-zero net, and how much in total.
SELECT COUNT(*)            AS affected_rows,
       COALESCE(SUM(amount), 0) AS net_total_increase
FROM transactions
WHERE fee_amount IS NULL
  AND status = 'success';

-- The same rows, per school, to know who sees a change.
SELECT s.name, COUNT(*) AS rows, COALESCE(SUM(t.amount), 0) AS net_increase
FROM transactions t
JOIN schools s ON s.id = t.school_id
WHERE t.fee_amount IS NULL AND t.status = 'success'
GROUP BY s.name
ORDER BY net_increase DESC;

-- To inspect individual rows before the deploy.
SELECT id, school_id, reference, amount, paid_at, created_at
FROM transactions
WHERE fee_amount IS NULL AND status = 'success'
ORDER BY id;
```

**Zero rows means no school sees any change at all.** If rows are returned, tell
the affected schools that their historical net totals were understated and now
match the transaction list and CSV they could already export.

## 7. Security

- Never commit a Paystack key. `.env` is ignored; `render.yaml` declares keys
  as `sync: false`; `paystack:check` prints only live/test classification.
- Never put the secret key in a Blade view or JavaScript. The browser only ever
  sees Paystack's hosted checkout URL.
- Webhook authenticity is the HMAC-SHA512 signature over the raw body checked
  with `hash_equals` before the payload is parsed; unsigned requests are `401`.
  Keep the key identical between Render and the dashboard.
- HTTPS everywhere: `ForceHttps` redirects plain HTTP in production and trusts
  Render's `X-Forwarded-Proto`; `SESSION_SECURE_COOKIE=true`.
- Logs: provider payloads are stored on the payout row (`payouts.response`),
  not printed; `last_error` is a short message; the school-facing UI never
  renders either. `paystack:check` and the recovery commands print references,
  masked account endings and one-line messages only.
- Payment initialization is rate-limited per client IP: **10 submits per
  minute and 60 per hour**, one shared `payment-initialize` bucket for
  `POST /pay/{school}/initialize` and the legacy
  `POST /s/{school}/payment/initialize` (registered in `bootstrap/app.php`,
  limits in `App\Support\PaymentInitializeLimiter`). The throttle runs before
  the controller, so a throttled submit creates **no** pending transaction and
  makes **no** Paystack call; the parent gets a `429` with `Retry-After`, the
  payment form re-rendered with their input and a plain-language message. The
  key is the client IP from `X-Forwarded-For` (Render's edge is a trusted
  proxy), never the proxy address, school, student or session. Counters live in
  the cache — the database-backed store in production (`CACHE_STORE=database`,
  section 1) — so all web instances share the same buckets; `php artisan
  cache:clear` resets them. The student-search autocomplete keeps its own,
  separate 60/min bucket; the callback and the webhook are not throttled.
- The two public Paystack bank lookups are rate-limited as well, each in its own
  bucket (registered in `bootstrap/app.php`, limits in
  `App\Support\BankLookupLimiter`). `GET /api/banks` (`bank-list`): **20/min and
  60/hour per client IP**. `GET /api/resolve-account` (`bank-resolve`):
  **10/min and 40/hour per client IP**, plus **60/hour per signed-in school** —
  one admin password per school means several admins are one credential across
  many sessions and addresses, and only a school-keyed bucket counts them as
  one. Both endpoints are unauthenticated and every request that reaches the
  controller is an outbound call on the *same* secret key that initialises
  payments and pays schools out, so the throttle runs before the controller: a
  throttled lookup makes **no** Paystack call and returns a `429` with
  `Retry-After` and a JSON body in the `{ok:false,error:"…"}` shape the
  registration and settings forms already display. The two buckets are separate
  from each other and from `payment-initialize`. The server-side re-verification
  on `POST /registration` (10/hour) and `PUT /settings/bank` (5/hour) is
  unchanged and still bounds those paths.
- **`/api/resolve-account` discloses the account-holder NAME only to a signed-in
  school admin.** It cannot be authenticated outright — registration is open
  self-service and the school does not exist yet — but the disclosure could be
  restricted, and was: an anonymous caller (the registration form) receives
  `{"ok":true,"verified":true}` and nothing else, so the endpoint can no longer
  be used to harvest names by running one known account number against the ~25
  Nigerian bank codes. A signed-in admin, editing the payout account they own,
  still gets the full `account_name`/`account_number`. A session revoked by a
  password change or reset (H6), one whose school has been deleted, and a
  session id carrying no fingerprint are all treated as anonymous — the check is
  `SchoolSession::school()`, not the raw id. Failure responses are byte-identical
  for both callers, so a failed lookup reveals nothing a successful one does not.
  Integrity is unaffected: `RegistrationController` and `SchoolBankDetailsService`
  both re-resolve server-side and store Paystack's answer, never the browser's.
  The registration form now shows "✓ Account number confirmed" rather than a
  name — deliberately not a claim that the account is active, funded or in good
  standing, only that the bank recognised the number. `GET /api/banks` stays
  fully public: it is the list of banks Paystack can pay out to and there is
  nothing in it to enumerate. Not yet done, tracked separately: a global ceiling
  on outbound resolutions, and caching the bank list (which needs `country`
  whitelisted first, or the cache key is caller-controlled).
- Password reset is rate-limited per client IP, each side in its own bucket:
  `POST /admin/forgot-password` **5/hour** (`password-reset-request`) and
  `POST /admin/reset-password` **10/hour** (`password-reset`). Only the POSTs are
  limited — both forms still render, so a locked-out admin sees the page and its
  message. Token brute force was never the risk (64 random hex characters); the
  risks were mail flooding a customer's inbox on our sending reputation, and
  **reset denial**: `Password::createToken()` deletes the school's existing token
  before inserting the new one, so repeated requests invalidate the link already
  in the admin's inbox. The controller therefore also honours the broker's own
  window (`config/auth.php` → `passwords.users.throttle`, 60s) via
  `recentlyCreatedToken()` before minting — **a second request inside 60s sends
  no new mail and keeps the live link working**, with the same neutral response.
  The reset endpoint answers "no such school" and "bad token" identically, so it
  cannot be used to enumerate which addresses are registered schools. An SMTP
  failure is reported and answered neutrally rather than becoming a `500`.
  *Operator note:* an admin locked out by the 5/hour limit has no self-service
  path until it decays — same remedy as `admin-login` (wait, or `php artisan
  cache:clear`). Mail is still sent synchronously from the request.
- Least privilege: only the operator who runs payouts needs Render shell access;
  school admins have no path to any of these commands (no HTTP route exists).
- Tickets and chat: reference payouts by `PO-…` and transactions by their
  reference; never paste keys, full account numbers or raw webhook bodies.

## 8. Stop / rollback procedure

There is no single "maintenance" Artisan command for money in this project;
use the layers that exist, in this order, and record what you did.

1. **Stop new payouts (keeps collections running)** — in Render, suspend the
   `laravel-queue-worker` service and the `laravel-payout-reconciliation` cron
   (manual Render operations). Settled payments still create `pending`
   obligations; nothing is transferred until the worker is resumed, at which
   point the backlog is sent through the normal claim guard.
2. **Stop new payments** — Paystack side: disable the live API key or switch
   the business to test mode (manual Paystack operation); the application then
   shows "Unable to initialize payment." Application side: `php artisan down`
   on the web service takes the whole site (including the public payment pages
   and the webhook) offline; Paystack will retry undelivered webhooks for a
   while, so keep the outage short and run `payouts:lookup --stale` and
   `payouts:run --dispatch` after `php artisan up`.
3. **Rollback a deploy** — Render → laravel-app → Rollback to the previous
   image (manual Render operation). Migrations are additive and are not rolled
   back; the payout ledger is unaffected.
4. **Resume** — `paystack:check --production` clean → resume the worker → watch
   the first payouts move `pending → processing → success` → resume the cron.
