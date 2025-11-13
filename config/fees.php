<?php

return [
    // Percentage markup added to the customer's payable amount.
    // Keep this higher than the Paystack percentage to cover fees.
    'markup_percent' => (float) env('MARKUP_PERCENT', 2.5),
];
