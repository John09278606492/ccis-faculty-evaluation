<?php

return [
    /*
    | A stable, private key used to prevent duplicate anonymous submissions
    | without storing evaluator IDs, IP addresses, or browser details.
    */
    'fingerprint_key' => env('EVALUATION_FINGERPRINT_KEY') ?: env('APP_KEY'),
];
