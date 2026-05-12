<?php

return [
    'features' => [
        'ai_scan' => [
            'allowed_plans' => ['free', 'basic', 'plus', 'premium'],
            'requires_quota' => true,
            'messages' => [
                'no_subscription' => 'Subscription required to scan.',
                'quota_exceeded' => 'Scan limit reached for this billing period.',
            ],
        ],
        'recurring_expenses' => [
            'allowed_plans' => ['plus', 'premium'],
            'requires_quota' => false,
            'messages' => [
                'denied' => 'Recurring expenses are not available on your plan.',
            ],
        ],
        'csv_export' => [
            'allowed_plans' => ['basic', 'plus', 'premium'],
            'requires_quota' => false,
            'messages' => [
                'denied' => 'CSV export is not available on your plan.',
            ],
        ],
        'analytics_dashboard' => [
            'allowed_plans' => ['plus', 'premium'],
            'requires_quota' => false,
            'messages' => [
                'no_subscription' => 'Analytics dashboard is not available on your plan.',
                'denied' => 'Analytics dashboard is not available on your plan.',
            ],
        ],
        'voice_expense' => [
            'allowed_plans' => ['premium'],
            'requires_quota' => false,
            'messages' => [
                'no_subscription' => 'Voice expense entry is only available on Premium.',
                'denied' => 'Voice expense entry is only available on Premium.',
            ],
        ],
    ],
];
