<?php

return [
    'rate_limits' => [
        'starter' => (int) env('API_RATE_STARTER', 60),
        'pro' => (int) env('API_RATE_PRO', 300),
        'business' => (int) env('API_RATE_BUSINESS', 1000),
    ],

    // Operations: how many machines one may touch, and how many may run or be paused at once.
    'operations' => [
        'machines' => (int) env('API_OPERATION_MACHINES', 500),
        'running' => (int) env('API_OPERATIONS_RUNNING', 10),
    ],
];
