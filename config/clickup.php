<?php

return [
    'api_token' => env('CLICKUP_API_TOKEN'),
    'signing_secret' => env('CLICKUP_SIGNING_SECRET'),

    'team_id' => env('CLICKUP_TEAM_ID'),
    'team_ids' => array_values(array_filter(array_map(
        static fn($value) => trim((string) $value),
        explode(',', (string) env('CLICKUP_TEAM_IDS', ''))
    ))),
    'space_id' => env('CLICKUP_SPACE_ID'),
    'space_ids' => array_values(array_filter(array_map(
        static fn($value) => trim((string) $value),
        explode(',', (string) env('CLICKUP_SPACE_IDS', ''))
    ))),

    'allow_unverified' => env('CLICKUP_ALLOW_UNVERIFIED', env('APP_ENV', 'production') === 'local'),
    'push_time_entries' => (bool) env('CLICKUP_PUSH_TIME_ENTRIES', false),

    'reporting' => [
        'list_id' => env('CLICKUP_REPORT_LIST_ID'),
    ],

    'report_custom_fields' => [
        'task_id' => env('CLICKUP_REPORT_CF_TASK_ID'),
        'user' => env('CLICKUP_REPORT_CF_USER'),
        'time_in' => env('CLICKUP_REPORT_CF_TIME_IN'),
        'time_out' => env('CLICKUP_REPORT_CF_TIME_OUT'),
        'total_mins' => env('CLICKUP_REPORT_CF_TOTAL_MINS'),
        'notes' => env('CLICKUP_REPORT_CF_NOTES'),
    ],

    'custom_fields' => [
        'total_hours' => env('CLICKUP_CF_TOTAL_HOURS_ID'),
        'today_hours' => env('CLICKUP_CF_TODAY_HOURS_ID'),
        'week_hours' => env('CLICKUP_CF_WEEK_HOURS_ID'),
    ],
];


