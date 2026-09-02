<?php

return [
    // How long a review-request link stays valid before it expires.
    'request_ttl_days' => (int) env('REVIEWS_REQUEST_TTL_DAYS', 30),

    // Default minimum review body length (per-tenant override lives on sites.review_body_min_length).
    'body_min_length' => (int) env('REVIEWS_BODY_MIN_LENGTH', 20),

    // Reminder cadence (days after the request was sent) and the hard cap on reminders. Spec: day 3 and day 10,
    // then stop — reminder_count capped at 2.
    'reminder_days' => [3, 10],
    'reminder_cap' => 2,
];
