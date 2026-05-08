<?php

/**
 * Plan seed data.
 * Order: free, basic, plus, premium.
 * Run: php artisan plans:seed
 */
return [
    [
        'type' => 'free',
        'label' => 'Free',
        'price' => 0,
        'check_limit' => 20,
        'description' => "AI scans: 20/month (1 photo per scan)\nManual transactions: unlimited\nHistory: 1 month\nBasic categories only\nCategory visibility controls",
    ],
    [
        'type' => 'basic',
        'label' => 'Basic',
        'price' => 300,
        'check_limit' => 200,
        'description' => "AI scans: 200/month (1 photo per scan)\nManual transactions: unlimited\nHistory: unlimited\nCustom categories\nCategory visibility controls\nCSV export",
    ],
    [
        'type' => 'plus',
        'label' => 'Plus',
        'price' => 500,
        'check_limit' => 500,
        'description' => "AI scans: 500/month (multiple photos per scan)\nManual transactions: unlimited\nHistory: unlimited\nCustom categories\nCategory visibility controls\nRecurring transactions\nAnalytics dashboard\nCSV export",
    ],
    [
        'type' => 'premium',
        'label' => 'Premium',
        'price' => 1000,
        'check_limit' => 2000,
        'description' => "AI scans: 2000/month (multiple photos per scan)\nManual transactions: unlimited\nHistory: unlimited\nCustom categories\nCategory visibility controls\nRecurring transactions\nAdvanced analytics\nAdd expenses via voice messages\nCSV export",
    ],
];
