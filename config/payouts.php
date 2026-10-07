<?php

return [
    // H3: hours every school payout is held after its bank account changes, so a
    // change made from a hijacked session cannot move money before the school has
    // seen the emailed notice. An operator can lift it early with
    // `schools:approve-payouts`. Payments are collected as normal during the hold.
    'bank_change_hold_hours' => (int) env('PAYOUT_BANK_CHANGE_HOLD_HOURS', 48),
];
