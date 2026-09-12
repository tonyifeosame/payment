<?php

return [
    // Percentage markup added to the customer's payable amount.
    // Keep this higher than the Paystack percentage to cover fees.
    'markup_percent' => (float) env('MARKUP_PERCENT', 2.5),

    // Timezone for business-day reporting boundaries ("today", "this week") and
    // for showing payment times to school admins. Storage stays in app.timezone
    // (UTC); only the dashboard converts. Nigeria: WAT, UTC+1, no DST.
    'reporting_timezone' => env('REPORTING_TIMEZONE', 'Africa/Lagos'),
];
