# Local customer demo — School Fees Portal

A full walkthrough of the payment pipeline on your own machine, in **Paystack test
mode**. Nothing here touches Render, and nothing here can move real money.

```
payment -> webhook/callback -> settlement -> payout obligation -> queued job
        -> worker -> Paystack transfer -> receipt
```

---

## 0. One-time setup

Already done in this working copy, listed so you can redo it on another machine:

```bash
composer install
cp .env.example .env          # then: php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=DemoSeeder
```

`.env` is already configured for local work: SQLite, `QUEUE_CONNECTION=database`,
`CACHE_STORE=database`, `APP_URL=http://localhost:8000`, `MAIL_MAILER=log`.

### The one thing you must supply: Paystack test keys

Open your Paystack dashboard → **Settings → API Keys & Webhooks**, switch to
**Test mode**, and paste the pair into `.env`:

```
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxx
```

Then `php artisan config:clear`. **Never put `sk_live_` in this file.** The
`demo:webhook` helper refuses to run if it sees a live key, and the Render
blueprint keeps all keys out of Git entirely.

### Mail: pick one

| Option | Setup | Where the receipt appears |
|---|---|---|
| **Log** (default, zero install) | already set | `storage/logs/laravel.log` — full rendered HTML |
| **Mailpit** (nicer for a live demo) | `winget install Axllent.Mailpit`, then run `mailpit` | inbox UI at http://localhost:8025 |

To switch to Mailpit, in `.env` set `MAIL_MAILER=smtp` and uncomment
`MAIL_HOST` / `MAIL_PORT` / `MAIL_SCHEME`, then `php artisan config:clear`.

---

## 1. Start the three processes

Three terminals, all from the project root:

```bash
# 1 — the app
php artisan serve --host=127.0.0.1 --port=8000

# 2 — the queue worker: payouts and receipts are queued jobs and will sit
#     untouched without this. This is the local stand-in for the Render worker.
php artisan queue:work database --queue=default --tries=3 --backoff=30

# 3 — optional: Mailpit, if you chose it above
mailpit
```

A fourth, only if you want to show reconciliation (§8):

```bash
php artisan payouts:run --dispatch
```

> **Restart the worker after any `.env` change.** A long-running `queue:work`
> holds the configuration it booted with, so a key you paste into `.env` mid-demo
> is invisible to it. This bites in a way that looks like a bug: the payout fails
> with "Paystack secret key is not configured" while the web service is using the
> key perfectly well. Same reason production deploys restart the worker.

---

## 2. School / admin onboarding

Two paths — show whichever suits the audience.

**Pre-seeded school** (fastest, always works):

- Public page: http://localhost:8000/s/demo-academy/payment
- Admin login: http://localhost:8000/admin/login
  - School name: `Demo Academy`
  - Password: `demo-password`

**Live registration** at http://localhost:8000/registration/create — this is the
real onboarding flow a new customer sees. Note it calls Paystack's account-resolve
API server-side and **will reject the form if the account cannot be resolved**, so
it needs working test keys. If resolve fails during a demo, fall back to the
seeded school rather than debugging live.

On success the school is created, the admin is logged in automatically, and a
"here are your links" email is sent (visible in your log or Mailpit).

## 3. Fee / category setup

Logged in as the school admin:

- Categories: http://localhost:8000/s/demo-academy/categories
- Fee types: http://localhost:8000/s/demo-academy/subcategories

The seeded structure is:

| Category | Fee type | Price |
|---|---|---|
| School Fees | Primary - Term 1 | NGN 50,000.00 |
| School Fees | Secondary - Term 1 | NGN 80,000.00 |
| Uniform | Shirt | NGN 3,000.00 |
| Uniform | Trousers | NGN 4,000.00 |

Worth pointing out: every one of these pages is scoped to the bound school, and
route model binding 404s a record belonging to another school before controller
code runs. `TenantIsolationTest` covers this.

## 4. Public payment page

http://localhost:8000/s/demo-academy/payment — no login required. This is the URL
a school hands to parents.

Pick a category and fee type, enter an email you can check, submit. A ~2.5%
markup (`MARKUP_PERCENT`) is added on top of the fee to cover Paystack's cut; the
breakdown is stored on the transaction.

## 5. Paystack test checkout

You are redirected to Paystack's hosted checkout. Use a Paystack **test card** from
their docs (e.g. `4084 0840 8408 4081`, any future expiry, any CVV, OTP `123456`).
No real money moves.

## 6. Settlement

Paystack redirects back to `/payment/callback`, which:

1. re-verifies the reference **server-side** against the Paystack API — the
   browser's query string is never trusted;
2. settles the transaction exactly once;
3. records a durable payout obligation in the same database transaction;
4. queues `InitiateSchoolPayout` after commit;
5. queues the receipt email.

### Showing the webhook specifically

Paystack cannot reach `localhost`, so the browser callback is what settles a local
demo. To demonstrate the webhook path itself, either expose the app with a tunnel
(`ngrok http 8000`, then set the webhook URL in the Paystack dashboard to
`https://<tunnel>/paystack/webhook`), or use the offline helper:

```bash
php artisan demo:webhook charge.success        # signs and posts a charge event
php artisan demo:webhook transfer.success      # completes the payout state machine
```

It builds the same JSON Paystack sends and signs it with HMAC-SHA512 over the raw
body, which is exactly what the controller verifies. Two honest caveats:

- `charge.success` proves **transport and signature only** — settlement still
  re-verifies against the real Paystack API, so a forged webhook cannot invent a
  payment. That is the security property, demonstrated.
- `transfer.*` is fully real: it calls no Paystack API and genuinely drives the
  payout state machine.

A good thing to show a technical buyer: an unsigned or wrongly-signed webhook is
rejected with **401** before the body is parsed (`PaymentIntegrityTest` asserts it).

## 7. Payout obligation and queued payout

Watch terminal 2 — the worker picks up `InitiateSchoolPayout`, which:

- creates the Paystack transfer recipient for the school if it does not have one;
- atomically claims the payout `pending -> initiating` (so two workers cannot both
  send);
- initiates the transfer with **our** reference as the idempotency key;
- applies whatever status Paystack reports.

Inspect the ledger at any point:

```bash
php artisan tinker --execute="App\Models\Payout::latest()->get(['reference','status','amount','attempts','last_error'])->each(fn(\$p)=>print(\$p->reference.' '.\$p->status.' '.\$p->amount.PHP_EOL));"
```

Note for the demo: Paystack **test mode does not complete real transfers** — the
payout will typically park in `initiating` or come back `otp`/failed. That is the
state machine working correctly, not a bug. To show a completed payout end-to-end,
drive it with `php artisan demo:webhook transfer.success`.

## 8. Reconciliation

The hourly safety net, runnable on demand:

```bash
php artisan payouts:run --dry-run     # show what it would do, change nothing
php artisan payouts:run --dispatch    # record missing obligations and queue them
```

It never calls Paystack itself and never touches a payout that is already
`initiating`, `success`, `failed` or `needs_review` — so it is safe to run
repeatedly in front of an audience.

## 9. Receipt and email

The worker sends `PaymentReceiptMail`. Where to look:

- Log driver: `tail -f storage/logs/laravel.log`
- Mailpit: http://localhost:8025

**The email is a complete, self-contained receipt — it carries no link back to the
app.** It contains the payer's name and email, the reference, the payment method,
the status, and an itemised table ending in fee subtotal, service fee and total.
Verified output for the seeded Primary fee:

```
| School Fees | Primary - Term 1 | NGN 50,000.00 | 1 | NGN 50,000.00 |
**Fee Subtotal:**     NGN 50,000.00
**Service Fee:**      NGN  1,250.00
**Total Amount Paid:** NGN 51,250.00
```

The **signed URL lives on the receipt page**, not in the email: the page's download
button is a `URL::signedRoute` link that works anonymously and 404s if tampered
with. Reach the receipt page during a demo either as the paying browser (the
session carries `last_transaction_id` straight after payment) or as the owning
school admin.

Access requires one of three proofs — a valid URL signature, the paying browser
session, or the owning school admin — so receipt IDs cannot be enumerated
(`ReceiptAccessTest`). An anonymous, unsigned `/payment/receipt/{id}` returns 404,
not 403, so it does not even confirm the id exists.

Worth knowing if you demo a hand-made transaction: the email's school-name heading
is derived from `category->school` / `subcategory->school`, not from
`transaction->school_id`, so a transaction created without a category or
subcategory renders the receipt without the school name.

---

## Reset between demos

```bash
php artisan migrate:fresh
php artisan db:seed --class=DemoSeeder
```

`DemoSeeder` is idempotent and prunes fees you have removed from it, so re-running
it alone is also safe.

## What is deliberately NOT part of this

- **No Render deployment.** The pending `render.yaml` / Docker changes are
  uncommitted and unused here; local runs never touch them.
- **No live Paystack keys.**
- **The `tony-payment` service is untouched.**
