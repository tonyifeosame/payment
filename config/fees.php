<?php

return [
    // Percentage markup added to the customer's payable amount.
    // Keep this higher than the Paystack percentage to cover fees.
    'markup_percent' => (float) env('MARKUP_PERCENT', 2.5),

    // Timezone for business-day reporting boundaries ("today", "this week") and
    // for showing payment times to school admins. Storage stays in app.timezone
    // (UTC); only the dashboard converts. Nigeria: WAT, UTC+1, no DST.
    'reporting_timezone' => env('REPORTING_TIMEZONE', 'Africa/Lagos'),

    // H5: how long a checkout may stay `pending` before `payments:expire-pending`
    // asks Paystack what became of it. Nothing is marked failed on age alone — the
    // window only decides WHEN the transaction is verified; Paystack's answer
    // decides the outcome (success settles it, failed/abandoned/unknown reference
    // fails it, still-open leaves it). 24 hours is generous: a parent completes
    // Paystack's hosted checkout in minutes, and the webhook/callback for a
    // completed payment arrives within seconds of it.
    'pending_payment_expiry_hours' => (int) env('PENDING_PAYMENT_EXPIRY_HOURS', 24),
];
