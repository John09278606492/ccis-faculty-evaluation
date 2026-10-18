<?php

return [
    /*
    | A stable, private key used to prevent duplicate anonymous submissions
    | without storing evaluator IDs, IP addresses, or browser details.
    */
    'fingerprint_key' => env('EVALUATION_FINGERPRINT_KEY') ?: env('APP_KEY'),

    /*
    | Instructors see the scores they received only after the evaluation closes and at
    | least this many students have responded. Admins are not limited.
    */
    'min_responses_for_results' => (int) env('EVALUATION_MIN_RESPONSES', 5),
];
