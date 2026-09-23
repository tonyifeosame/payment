# FEYRA — Product Readiness Audit

The durable record of the readiness audit and what was done about each finding.

The audit originally existed only in a chat transcript, which made it unfindable
in a later session and forced one finding to be re-derived from the code. This
file is the fix for that: the status column is meant to be maintained here as
findings change, not restated somewhere else.

**Scope of this document.** It records the 26 original findings — 2 blockers,
6 high, 7 medium, 11 low — plus one finding discovered afterwards, tracked
separately at the end so the original count stays honest.

**What "Closed" means here.** The engineering work is done, reviewed, tested and
pushed. It does **not** mean the item needs nothing further: several closed
findings left deliberate operational follow-ups, listed per finding and gathered
in *Operational prerequisites* below.

Every commit referenced is on `feature/website-redesign`, branched from
`179a91f`.

---

## Status at a glance

| Severity | Closed | Deferred | Total |
|---|---|---|---|
| Blocker | B1, B2 | — | 2 |
| High | H1, H3, H4, H5, H6 | H2 | 6 |
| Medium | M1–M7 | — | 7 |
| Low | L1, L2, L3, L4, L5, L6, L8, L9, L10, L11 | L7 | 11 |
| **Total** | **24** | **2** | **26** |

Plus one new finding (N1, empty slug; closed), outside the original 26 — see
the last section.

---

## Blockers

### B1 — No operator path for payouts that stop

**Severity:** Blocker · **Status:** **CLOSED** · **Commit:** `b0874fa`

A payout in `failed`, `needs_review` or a stuck `initiating`/`processing` state
had no way back. The transitions existed in `Payout::ALLOWED_TRANSITIONS` but
nothing in `app/` invoked them, so the only recovery was a manual `UPDATE` on
production Postgres.

**What was done.** Operator commands `payouts:retry`, `payouts:release` and
`payouts:lookup`, each refusing states outside the transition table and writing
an immutable `payout_recovery_events` row in the same transaction as the state
change. Amount authority is re-proved against the payment before anything moves.

**Still required:** nothing.

---

### B2 — Paystack production prerequisites neither enforced nor documented

**Severity:** Blocker · **Status:** **CLOSED** · **Commit:** `5603a56`

Transfers draw on the Paystack balance, but Paystack settles card collections to
the merchant's bank by default — so the balance would be ₦0 and every transfer
rejected. OTP-on-transfers and an unregistered webhook URL were equally silent
failure modes, and nothing documented any of it.

**What was done.** `docs/production/paystack-runbook.md`, and a `paystack:check`
command that verifies keys, API base, webhook registration, balance and transfer
capability, with `--production`, `--json` and `--no-api` modes.

**Still required:** the runbook's §2 and §3 steps are a human checklist —
settlement destination set to balance, transfers enabled, OTP disabled, webhook
URL and secret registered.

---

## High

### H1 — Ambiguous `initiating` payouts never resolved automatically

**Severity:** High · **Status:** **CLOSED** · **Commit:** `a9ba7d2`

If a transfer request never reached Paystack, no `transfer.*` webhook would ever
arrive and the payout stayed "Initiating" indefinitely.

**What was done.** The hourly cron re-dispatches `initiating` payouts older than
~10 minutes; that job path only ever performs a status lookup and releases on a
definitive "no such transfer". It can never send a second transfer.

**Still required:** nothing.

---

### H2 — Per-transaction payout economics

**Severity:** High · **Status:** **DEFERRED — by explicit decision**

At a 2.5% markup, a per-payment transfer is loss-making below roughly ₦12,500
once Paystack's collection and transfer fees are counted, and the losses come out
of the same balance that funds every other school's payout.

**Decision.** Deferred deliberately and repeatedly throughout the audit. **The
fee configuration must not be changed:** `config/fees.php` `markup_percent`
stays at **2.5**. A later session recorded a business intention to move to 1.5%,
which has *not* been applied and should not be applied as part of any
engineering task.

**Why it stays open.** This is a pricing decision, not a defect — the options
(a minimum service fee, passing Paystack's fee through, or batching payouts per
school per day) are commercial choices with different consequences for schools.
No code change should pre-empt it.

**Still required:** a business decision, then a scoped implementation.

---

### H3 — School logos on ephemeral container storage

**Severity:** High · **Status:** **CLOSED** · **Commit:** `5a4366e`

A logo uploaded on the web container vanished on the next deploy and was never
visible to the worker that renders receipt emails, so receipts silently lost the
school's branding.

**What was done.** Logos moved to a `school_logos` table, one row per school,
shared by every container and surviving deploys.

**Still required:** nothing.

---

### H4 — Registration did not enforce unique school name or email

**Severity:** High · **Status:** **CLOSED** · **Commit:** `97d15a0`

The name is the login identifier and the email is the password-reset identifier.
Duplicates meant a school that could never log in, or never reset its password —
silently.

**What was done.** Case-insensitive uniqueness validation at registration and on
rename, backed by case-insensitive unique indexes. Identity lookups fail closed:
two matches is treated as no match, never "the first one".

**Still required:** nothing.

---

### H5 — Declined and abandoned payments stayed `pending` forever

**Severity:** High · **Status:** **CLOSED** · **Commit:** `99ca563`

Nothing ever wrote the `failed` status, so the dashboard's "awaiting
confirmation" count grew without bound and a school could not distinguish a
parent mid-checkout from a card declined months earlier.

**What was done.** A verify-driven lifecycle: `payments:expire-pending` asks
Paystack what became of a stale checkout and records its answer. Age alone never
decides the outcome; success settles it like a late webhook.

**Still required:** nothing. `PENDING_PAYMENT_EXPIRY_HOURS` defaults to 24.

---

### H6 — No session regeneration on login, no invalidation on logout or password change

**Severity:** High · **Status:** **CLOSED** · **Commit:** `97d15a0`

Session fixation was possible, and a hijacked session survived a password change
or reset.

**What was done.** `App\Support\SchoolSession` centralises the lifecycle: login
regenerates the session id, logout invalidates and rotates the CSRF token, and a
fingerprint of the password hash revokes every *other* session when the password
changes — the same mechanism Laravel's own `AuthenticateSession` uses.

**Still required:** nothing.

---

## Medium

### M1 — Public payment initialization was unthrottled

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `edcabbe`

Every anonymous submit created a pending row and called Paystack.

**What was done.** A `payment-initialize` limiter — 10/minute and 60/hour per
client IP, one bucket shared by the canonical and legacy URLs. The throttle runs
before the controller, so a throttled submit creates no row and makes no
provider call.

**Still required:** nothing.

---

### M2 — `/api/banks` and `/api/resolve-account` unauthenticated and unthrottled

**Severity:** Medium · **Status:** **CLOSED** · **Commits:** `bde5823`, `07aa464`

Two public endpoints, each request an outbound call on the same secret key that
initialises payments and pays schools out. `resolve-account` was additionally a
public *(account number, bank code) → account-holder name* oracle.

**What was done, in two parts.**
`bde5823` — separate named limiters: `bank-list` at 20/min and 60/hour per IP;
`bank-resolve` at 10/min and 40/hour per IP plus 60/hour per signed-in school.
`07aa464` — the resolved **name** is disclosed only to a signed-in admin.
Registration cannot be authenticated (the school does not exist yet), so the
access could not be restricted but the disclosure could: an anonymous caller now
receives `{"ok":true,"verified":true}` and nothing more.

**Note.** This is risk reduction, not elimination — an anonymous *existence*
check remains, throttled. `/api/banks` stays fully public by design: it is the
list of banks Paystack can pay out to and there is nothing in it to enumerate.

**Still required:** two items were split out and remain open — a global ceiling
on outbound resolutions, and bank-list caching (which needs the `country`
parameter whitelisted first, or the cache key is caller-controlled).

---

### M3 — Timezone inconsistency across reporting surfaces

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `d6ed7a7`

Only the dashboard converted to the reporting zone. A payment settled between
00:00 and 01:00 Lagos was dated one day on the dashboard and the previous day on
the ledger, the CSV and the parent's receipt.

**What was done.** `App\Support\BusinessTime` — one definition of the reporting
zone, applied to every display surface **and** to the date-filter boundaries,
which had to move together: converting only the display would have left a row
shown as one day excluded by that day's filter. Storage stays UTC.

**Note.** `RunPayouts`' `--day`/`--since` are deliberately **not** converted:
they are operator scoping for money movement, not presentation. Runbook §6b.

**Still required:** nothing. Exports run after this change will not byte-match
ones run before it, for payments in that late-night window.

---

### M4 — Unpriced and zero-priced fees reached checkout

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `07cbcf7`

A fee with no amount set rendered as "₦0", was selectable, created a zero-value
pending row and asked Paystack to charge nothing — so the only thing refusing
the payment was the provider.

**What was done.** Two protections: the public page excludes fees without a
positive price, and `PaymentCheckoutService` refuses one server-side before any
row or provider call. Unpriced fees remain valid **drafts** on the admin side —
the fee list still shows them as "Not set" — because creating a fee type before
deciding its amount is intentional.

**Still required:** a decision on a provider minimum. A sub-minimum but positive
fee (a ₦1 fee → 103 kobo) still fails at Paystack; Paystack's documented minimum
is not recorded anywhere in this repository.

---

### M5 — Failed jobs invisible, PostgreSQL locking untested, no CI

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `3d382c1`

Nothing surfaced a failed job; only `InitiateSchoolPayout` had a `failed()` hook.
Four `PostgresRowLockTest` tests had never executed anywhere, because they skip
unless `PGSQL_TEST_HOST` is set — and SQLite compiles `lockForUpdate()` to an
empty string. There was no CI at all.

**What was done.** A read-only `jobs:check` command reporting failed jobs, queue
depth and the payouts the hourly cron deliberately does *not* re-queue
(`pending` with `attempts >= 1`, plus `failed`/`needs_review`), added to the
existing cron chain; a `Queue::failing` listener so every job that exhausts its
retries logs once; and `.github/workflows/ci.yml` running the SQLite suite, a
changed-file Pint check and the PostgreSQL row-lock tests against a real
`postgres:16` service — with a guard that **fails the build if those tests
silently skip**, since a skipped test otherwise reports success.

**Note on alerting.** Render *does* support cron-failure notification by email,
but it is a dashboard setting that cannot be declared in `render.yaml` and whose
default is undocumented. The design therefore does **not** rely on it: the
primary signal is mail through the already-configured mailer, and the non-zero
exit is secondary.

**Still required:** the Render operational items below.

---

### M6 — Password-reset flow unthrottled and untested

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `fc629c5`

Both reset POSTs were the last unauthenticated endpoints with no limit of any
kind. Token brute force was never the risk — the token is 64 random hex
characters. The real risks were mail flooding a customer's inbox, an enumeration
oracle, and **reset denial**: `Password::createToken()` deletes the existing
token before inserting a new one, so repeated requests invalidated the link
already sitting in the admin's inbox.

**What was done.** `5/hour` on the request side and `10/hour` on the reset side,
per IP, each in its own bucket; the broker's own 60-second window honoured via
`recentlyCreatedToken()` so a live link is not rotated; identical error messages
for "no such school" and "bad token"; and an SMTP failure reported rather than
surfaced as a 500.

**Note.** This finding was re-derived from the code in a later session because
the audit list had been lost — it matched M6 exactly, including the
`createToken` bypass. That is why this document exists.

**Still required:** nothing. An admin locked out by the 5/hour limit has no
self-service path until it decays; the remedy is in runbook §7.

---

### M7 — One shared credential per school, no action audit log

**Severity:** Medium · **Status:** **CLOSED — narrowed, see note** · **Commit:** `6563f28`

A school has exactly one credential (`schools.admin_password`), so no record can
name a person. Separately, almost no admin mutation left any trace of what
changed.

**What was done.** A `school_audit_events` table modelled on
`payout_recovery_events`: immutable, written in the same transaction as the
mutation it records. Instrumented for identity, money and destructive actions —
profile name/email change, password change, bank change, fee create/update/delete,
current-term change, category delete, class-level delete. Six previously
non-transactional mutations were given narrow transaction boundaries containing
only the mutation and its audit row; `ClassLevelController`'s existing
transaction was reused rather than nested.

**Note — what this does and does not answer.** `actor` is a **role** and
`actor_session` is a SHA-256 of the session id. Together they answer *when*,
*which school*, *what changed* and *was it one sitting* — never *which member of
staff*. Naming a person would require separate admin identities per school,
which was deliberately out of scope: it would touch the authentication core the
audit explicitly flagged as already production-ready. **The finding's title is
therefore only half-closed by design**, and that was the accepted trade-off.

**Still required:** the table has no retention or pruning and grows unboundedly.
Multiple admin identities remain an open product decision.

---

## Low

### L1 — Quantity forced to 1 only when the category name contains "school fee"

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `9595645`

`PaymentCheckoutService` forces `quantity = 1` by matching the string
`"school fee"` against the category's **name**, which an admin can edit freely. A
category called "Tuition" or "Fees" therefore accepts a quantity up to 100.

**Decision.** Quantity is an explicit per-fee setting,
`subcategories.allows_quantity` (boolean, NOT NULL, default `false`), set by the
school on the fee form ("Allow multiple units") and recorded in the audit trail
with the fee's other fields.

**Change.**

- *Migration and backfill.* Existing **general** fees (`academic_term_id IS
  NULL`) whose category name does not contain "school fee" are set to `true`,
  which keeps today's behaviour for uniforms and books. Term fees stay `false`
  whatever their category is called, because a term fee is charged once per
  student per term and a payment is recorded against exactly one student. The
  migration is the only remaining use of the name rule. Transactions, prices and
  the markup are untouched, and it names no production fee ids.
- *Checkout.* A quantity other than 1 on a fee with `allows_quantity = false` is
  refused with a validation error on `quantity`. It is no longer silently
  rewritten to 1. The 1–100 range is unchanged.
- *Payment page.* Each fee carries `allows_quantity`; the page shows the quantity
  input (`min=1`, `max=100`) only for fees that allow it. The category-name check
  is gone from the JavaScript.

**Known behaviour changes.** A term fee in a category not named "school fee" (for
example "Tuition") no longer accepts a quantity above 1. A school that wants
multiple units on such a fee must tick the setting. Renaming a category no longer
changes what parents can pay.

**Not done.** Production data was not inspected, so which existing fees change
behaviour is unknown. The read-only queries prepared during the investigation
(Q0–Q7, kept outside the repository) answer that.

**Still required:** deployment with migration
`2026_09_26_000000_add_allows_quantity_to_subcategories_table`; optionally run
the Q0–Q7 queries first to see which term fees stop accepting a quantity.

---

### L2 — `initialize` retried any failed response with the same reference

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `7cd7fef`

`retry(3, 200, throw: false)` had no predicate, so a definitive 4xx was re-sent
three times. It mattered most when a first attempt had actually succeeded but
its response was lost: the re-sends returned "Duplicate Transaction Reference"
and the parent was told initialization failed for a checkout that existed.

**What was done.** The same classification `PaystackService::lookupClient()`
already used — retry only a connection failure or a 5xx. The reference is
unchanged and remains Paystack's idempotency key.

**Still required:** nothing.

---

### L3 — "Official proof of payment" printed on pending and failed receipts

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `818fffa`

**What was done.** All three renderers (web, PDF, email) already computed
`$isSuccess`; the claim is now gated on it, and a non-settled receipt says it is
a record of an attempt instead.

**Still required:** nothing.

---

### L4 — Registration flashed the raw SMTP exception

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `818fffa`

The exception text — SMTP host, port, provider error — was flashed verbatim to
an unauthenticated registrant's screen.

**What was done.** Reported, never shown; a neutral notice replaces it. The
account is created and signed in either way. Same handling M6 established for
the password-reset send.

**Still required:** nothing.

---

### L5 — Dead views and unbranded emails

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `debb069`

`login.blade.php`, `welcome.blade.php` and `transactions/create.blade.php` were
unreferenced; `school_links` and `school_password_reset` were still pre-rebrand
templates, and the fee types were listed as "Subcategories".

**What was done.** The three views removed after confirming no `view()`,
`@include`, `@extends` or dynamic reference anywhere; both emails rebranded to
FEYRA and "Subcategories" changed to "Fee types". The mailable classes were
**not** touched, so every link, token and expiry rule is provably unchanged.

**Note.** `resources/views/components/app-layout.blade.php` became orphaned (its
only consumer was the deleted `transactions/create`) and its nav points at
routes the URL migration removed. It was deliberately **kept** — it was outside
the finding, and removing it is a separate decision.

**Still required:** nothing.

---

### L6 — No empty state on the public payment page

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `818fffa`

A school with nothing payable rendered the full checkout form with empty
dropdowns and no explanation. M4 widened this: with draft fees now filtered out,
a school whose fees are all unpriced hits it even though its categories are
populated.

**What was done.** A page-level empty state naming the school, with the school's
contact details still in the footer. The form's script is loaded only when the
form is present.

**Still required:** nothing. A school mid-setup will now see an explanation on
its public link rather than a broken form.

---

### L7 — Per-IP throttles can lock out a shared connection

**Severity:** Low · **Status:** **DEFERRED**

Login, contact, bank change, password change and password-reset request are all
`5/hour` per IP. A school office behind one NAT — or any user on Nigerian mobile
CGNAT — shares that bucket with unrelated people. It bites hardest on
`admin-login` and `password-reset-request`, which are the *recovery* paths.

**Why deferred.** Raising the limits trades brute-force protection for
convenience on exactly the endpoints that protect credentials. Choosing the
number needs real traffic data on IP concentration, which is not available from
the codebase. **This is a traffic, security and product decision together**, and
should not be tuned by guesswork.

**Still required:** a decision informed by production traffic. Do not change the
current throttles as part of unrelated work.

---

### L8 — Public student search discloses names, class and masked admission number

**Severity:** Low as filed — **recommended reclassification: Medium (privacy)** ·
**Status:** **CLOSED** · **Commit:** `6fcc9b6`

A two-character query returns up to ten students' full names, class and masked
admission number — unauthenticated, per school, at 60/minute. The original audit
called it "inherent to the design, but worth a privacy statement or tighter
thresholds".

**Actual exposure (reproduced).** Wider than filed: an unescaped LIKE wildcard
(`__`, `%%`) listed students without knowing any name; a sweep of two-letter
queries enumerated a school's entire active roster; admission numbers matched on
any fragment, so a masked number could be rebuilt one character at a time; and a
failed payment submit echoed back the name, class and masked number of whatever
`student_id` was posted, so the roster could be walked by id without searching.

**Decision.** The parent must already know the student: the lookup requires the
student's **full name and complete admission number**, and reveals nothing
before both match.

**What was done.**

- The lookup returns a student only when the complete admission number (existing
  normalisation) and the full name (ignoring case and runs of whitespace) belong
  to the same **active** student of that school. No fuzzy or partial matching —
  not a surname, not a fragment, not a wildcard.
- Every failure — name only, partial number, wildcard, mismatch, inactive or
  other-school student, malformed input — returns the identical
  `{"student": null}`; the page shows one generic "not found" message.
- On success the page shows name, class and masked admission number, as before.
- The lookup is POST (same paths, route names and 60/minute throttle), so names
  and admission numbers stay out of URLs and access logs.
- After a failed submit, a student is re-selected only when the submitted name
  and number re-verify to that same id; a bare `student_id` is never echoed back.

Tenant isolation, the active-student check and checkout validation are
unchanged.

**Known behaviour change.** Parents can no longer browse by partial name; they
need the admission number (which the share page already tells them) and the
name exactly as the school recorded it, in the same word order. Accents are not
folded.

**Still required:** nothing in code. A published privacy statement, which the
original audit suggested, remains a separate content decision.

---

### L9 — Dashboard net diverged from the transaction list and CSV

**Severity:** Low as filed — **was in practice a reporting-integrity defect** ·
**Status:** **CLOSED** · **Commit:** `a2abc79`

The aggregates summed `transactions.fee_amount`; the list, CSV, receipts and the
dashboard's own recent-payments list read `receiptBreakdown()`, which for a row
with no usable `base_amount` answers "the whole charge". A legacy payment
therefore counted as ₦0 in a total while showing its full amount in the rows
beneath it.

**What was done.** `Transaction::netAmountExpression()` —
`COALESCE(fee_amount, amount)` — used by the dashboard totals, the category
breakdown and the student-history total. No transaction amount, settlement,
payout calculation or receipt semantics changed, and **no migration or data
modification**.

**Note.** The student-history total was a second instance of the same defect,
not named in the original finding: it rendered directly above the rows it
totals. It was included.

**Still required:** **run the runbook §6c read-only query against production
before deploying** to learn whether any affected rows exist. Zero rows means no
school's numbers move at all; otherwise their historical net totals will
increase to match the CSV they could already export. Net figures can only go up,
never down.

---

### L10 — No reserved-slug list for admin routes

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `b586ae1`

A school's slug is its route key, so `/admin/{slug}/…` competes with the literal
admin routes registered before it. Resolving candidates through the router showed
that **exactly one** shadows: `admin/reset-password/{token}`'s wildcard swallows
the next segment, so every page of a school slugged `reset-password` resolved to
the password-reset form.

**What was done.** `School::RESERVED_SLUGS` plus `availableSlugFor()`, which
keeps the existing `-1`/`-2` collision behaviour and treats a reserved segment as
already taken. The list is wider than the one proven collision, because what made
`reset-password` dangerous was a wildcard child added to a segment that looked
safe — and a guard test now fails if a literal admin route is ever added without
reserving its segment.

**Note.** No existing school was renamed and **no migration was added**. To check
production:

```sql
SELECT id, name, slug FROM schools
WHERE slug IN ('admin','login','logout','forgot-password','reset-password','manifest');
```

Only a `reset-password` row would actually be broken.

**Still required:** optionally run the query above.

---

### L11 — Recipient-creation failure escaped after the payout was claimed

**Severity:** Low · **Status:** **CLOSED** · **Commit:** `7cd7fef`

`ensureRecipientForSchool()` uses the retrying client, which re-throws when its
attempts run out. The call sits outside `initiateTransfer()`'s try block, so the
exception escaped the method entirely and came out of the job **after**
`claimForTransfer` had moved the payout to `initiating` — stranding it until
H1's hourly lookup released it.

**What was done.** A targeted catch returning `rejected`. That classification is
provably correct: `POST /transferrecipient` creates a payout *destination* and
never moves money, and the `/transfer` call is not reached — so the claim can be
released immediately and the payout is retryable by B1's tooling. Duplicate-
transfer protection is untouched.

**Still required:** nothing. One pre-existing residual: if recipient creation
succeeds but its response is lost, a later attempt creates a second recipient at
Paystack. Harmless — recipients are destinations, not money.

---

## New findings (outside the original 26)

### N1 — Symbol-only school names slugify to an empty string

**Severity:** Medium · **Status:** **CLOSED** · **Commit:** `626a5ee`

A school name with no sluggable characters — symbols such as `###`, emoji, or
CJK such as `学校` — passes validation (`required|string|max:255` plus the
uniqueness rules) but `Str::slug()` reduces it to an empty string, and
`School::availableSlugFor()` returned that empty string as the slug.

**Actual impact (reproduced).** Worse than malformed URLs: Laravel refuses to
generate a route with an empty parameter, so no `/admin//dashboard` URL is ever
produced. Registration saved the school and signed the admin in, then threw
`UrlGenerationException` building the dashboard link — a 500, and no welcome
email. From then on every login and the `/admin` entry point returned 500, no
admin or public payment URL for the school could be generated or matched, and
the name could not be registered again because the half-created row held it. A
second such school received the slug `-1`, which works.

**Discovered during** the L10 investigation, while centralising slug generation.
It is **not** one of the original 26 findings and must not be counted as one.

**Decision.** Such names stay valid (no name validation added); they get a
fallback slug instead.

**Change.**

- `School::availableSlugFor()` uses `school` (`School::FALLBACK_SLUG`) as the
  base when `Str::slug()` returns an empty string, then the existing `-1`, `-2`
  loop: `###` becomes `school`, the next such school `school-1`. Every name that
  already produced a slug gets the same slug as before; reserved-slug handling
  is unchanged.
- `School::slugIsUnavailable('')` is `true`, so an empty slug can never be
  minted even by a future caller that bypasses the fallback.
- Data migration `2026_09_27_000000_repair_empty_school_slugs` gives any school
  stored with `slug = ''` the slug the same rules now produce. It touches only
  empty slugs — never an existing non-empty slug, including `-1` / `-2` — and no
  other column. Such a school had no URL that could ever have been generated or
  shared, so re-slugging it breaks nothing. Irreversible by design.

**Not done.** Production data was not inspected, so whether any school has an
empty slug is unknown; the migration handles either case.

**Still required:** deployment with migration
`2026_09_27_000000_repair_empty_school_slugs`. To see beforehand whether it will
change anything:

```sql
SELECT id, name, slug, created_at FROM schools WHERE slug = '';
```

---

## Operational prerequisites

Engineering closure is not deployment readiness. These remain outstanding and
are **not** code changes:

1. **`OPERATIONS_ALERT_EMAIL`** — set on the Render cron service. Unset, it falls
   back to `MAIL_FROM_ADDRESS` (see `config/operations.php`).
2. **`MAIL_*` on the cron service** — the full set. Without it `MAIL_MAILER`
   falls back to `log` and an unhealthy `jobs:check` writes its alert to stderr
   instead of reaching anyone, which is the exact blind spot M5 exists to close.
   The Blueprint declares these as `sync: false`; the values must be supplied in
   the dashboard.
3. **Cron-failure notifications** — decide whether to enable Render's
   "Only failure notifications" level for the cron service. It is a dashboard
   setting that cannot be declared in `render.yaml`, and nothing in the codebase
   assumes it is on.
4. **Run the §6c query before deploying** (runbook) to determine whether legacy
   net-reporting rows exist and whose numbers will therefore change.
5. **B2's runbook checklist** — Paystack dashboard settlement destination,
   transfers enabled, OTP disabled, webhook URL and secret registered.

---

## Summary

**24 of the 26 original findings are closed.** All blockers, five of six high,
all seven medium, and ten of eleven low.

**Two are deferred, each by decision rather than omission:**

- **H2** — fee economics. The 2.5% configuration stays as it is.
- **L7** — per-IP throttles, pending traffic data and a security/product call.

**One new finding (N1, empty slug) is tracked separately** and is not counted in
the 26. It is closed.

**This does not make the product production-ready.** Closing an audit finding
means the engineering work is done, reviewed, tested and pushed — nothing more.
Deployment still depends on the operational prerequisites above, on the H2
pricing decision, and on the judgement that the deferred items are acceptable
risks for launch. Several closed findings also left explicit follow-ups: M2's
global resolve ceiling and bank-list caching, M4's provider-minimum decision,
M5's Render alerting configuration, M7's retention and identity questions,
L9's pre-deployment query, and the L1 and N1 migrations (with their optional
pre-deployment queries). Those are recorded per finding above and should be
read as part of the launch decision, not treated as already handled.
