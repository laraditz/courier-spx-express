<?php

return [
    // Issued by SPX staff when the integration is approved; signs every request.
    'app_id'      => env('SPXEXPRESS_APP_ID'),
    'app_secret'  => env('SPXEXPRESS_APP_SECRET'),
    // The sender account the shipments are booked against. Sent in every request
    // body, not as a header — SPX scopes orders to this pair, not to the app.
    'user_id'     => env('SPXEXPRESS_USER_ID'),
    'user_secret' => env('SPXEXPRESS_USER_SECRET'),
    // SPX's base_info.service_type. Malaysia offers only 1 (Standard Service); a
    // ShipmentPayload/RatePayload serviceCode overrides it when set.
    'service_type' => env('SPXEXPRESS_SERVICE_TYPE', 1),
    // Used when a ShipmentPayload carries no fulfillment mode. Drop-off by default:
    // a pickup needs a timeslot SPX has to offer for the sender's postcode, and an
    // account without pickup coverage has every pickup booking rejected.
    'default_fulfillment' => env('SPXEXPRESS_DEFAULT_FULFILLMENT', 'dropoff'),
    // Seconds of clock skew tolerated on an inbound webhook's `timestamp` header
    // before the push is rejected as a replay. Set to 0 to disable the check.
    'webhook_timestamp_tolerance' => env('SPXEXPRESS_WEBHOOK_TIMESTAMP_TOLERANCE', 900),
    'sandbox'     => env('SPXEXPRESS_SANDBOX', false),
    'base_url'    => 'https://spx.com.my',
    'sandbox_url' => 'https://test-stable.spx.com.my',
    'timeout'     => 30,
];
