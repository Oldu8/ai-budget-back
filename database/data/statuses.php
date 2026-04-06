<?php

/**
 * Subscription/payment status names (Stripe-aligned).
 * Run: php artisan db:seed --class=StatusSeeder or statuses:seed
 */
return [
    'active',
    'canceled',
    'pending',
    'trialing',
    'past_due',
];
