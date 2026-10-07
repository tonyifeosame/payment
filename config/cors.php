<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (L4)
|--------------------------------------------------------------------------
|
| None. Every browser request this application makes — the bank list, the
| account-name check, the student lookup — comes from its own pages on its own
| origin, and same-origin requests need no CORS headers at all. Without this
| file the framework default applied: `Access-Control-Allow-Origin: *` on
| api/*, which let any website have its visitors' browsers call the bank
| lookups (each one an outbound Paystack call on our secret key) from
| thousands of addresses. With no paths listed, no CORS headers are sent and
| browsers refuse cross-origin reads, as they should.
|
| Server-to-server callers (Paystack's webhook) do not use CORS and are
| unaffected.
|
*/

return [

    'paths' => [],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
