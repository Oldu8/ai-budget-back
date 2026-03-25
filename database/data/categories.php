<?php

/**
 * Category seed data.
 * Structure: key => name, parent_key => category_id reference, label, visibility_tag.
 * Run: php artisan categories:seed
 */
return [
    [
        'key' => 'home',
        'parent_key' => null,
        'label' => 'Home',
        'visibility_tag' => null,
    ],
    ['key' => 'home_groceries', 'parent_key' => 'home', 'label' => 'Groceries', 'visibility_tag' => null],
    ['key' => 'home_snacks', 'parent_key' => 'home', 'label' => 'Snacks', 'visibility_tag' => null],
    ['key' => 'home_drinks', 'parent_key' => 'home', 'label' => 'Drinks', 'visibility_tag' => null],
    ['key' => 'home_household', 'parent_key' => 'home', 'label' => 'Household', 'visibility_tag' => null],
    ['key' => 'home_other', 'parent_key' => 'home', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'kids',
        'parent_key' => null,
        'label' => 'Kids',
        'visibility_tag' => 'kids',
    ],
    ['key' => 'kids_clothes', 'parent_key' => 'kids', 'label' => 'Clothes', 'visibility_tag' => 'kids'],
    ['key' => 'kids_toys', 'parent_key' => 'kids', 'label' => 'Toys', 'visibility_tag' => 'kids'],
    ['key' => 'kids_food', 'parent_key' => 'kids', 'label' => 'Food & Formula', 'visibility_tag' => 'kids'],
    ['key' => 'kids_care', 'parent_key' => 'kids', 'label' => 'Care & Hygiene', 'visibility_tag' => 'kids'],
    ['key' => 'kids_other', 'parent_key' => 'kids', 'label' => 'Other', 'visibility_tag' => 'kids'],

    [
        'key' => 'transport',
        'parent_key' => null,
        'label' => 'Transport',
        'visibility_tag' => null,
    ],
    ['key' => 'transport_taxi', 'parent_key' => 'transport', 'label' => 'Taxi', 'visibility_tag' => null],
    ['key' => 'transport_public', 'parent_key' => 'transport', 'label' => 'Public Transport', 'visibility_tag' => null],
    ['key' => 'transport_flights', 'parent_key' => 'transport', 'label' => 'Flights', 'visibility_tag' => null],
    ['key' => 'transport_trains', 'parent_key' => 'transport', 'label' => 'Trains', 'visibility_tag' => null],
    ['key' => 'transport_other', 'parent_key' => 'transport', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'car',
        'parent_key' => null,
        'label' => 'Car',
        'visibility_tag' => 'car',
    ],
    ['key' => 'car_fuel', 'parent_key' => 'car', 'label' => 'Fuel', 'visibility_tag' => 'car'],
    ['key' => 'car_parking', 'parent_key' => 'car', 'label' => 'Parking', 'visibility_tag' => 'car'],
    ['key' => 'car_service', 'parent_key' => 'car', 'label' => 'Service', 'visibility_tag' => 'car'],
    ['key' => 'car_wash', 'parent_key' => 'car', 'label' => 'Car Wash', 'visibility_tag' => 'car'],
    ['key' => 'car_other', 'parent_key' => 'car', 'label' => 'Other', 'visibility_tag' => 'car'],

    [
        'key' => 'housing',
        'parent_key' => null,
        'label' => 'Housing',
        'visibility_tag' => null,
    ],
    ['key' => 'housing_rent', 'parent_key' => 'housing', 'label' => 'Rent', 'visibility_tag' => 'rent'],
    ['key' => 'housing_utilities', 'parent_key' => 'housing', 'label' => 'Utilities', 'visibility_tag' => null],
    ['key' => 'housing_furniture', 'parent_key' => 'housing', 'label' => 'Furniture', 'visibility_tag' => null],
    ['key' => 'housing_repairs', 'parent_key' => 'housing', 'label' => 'Repairs', 'visibility_tag' => null],
    ['key' => 'housing_other', 'parent_key' => 'housing', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'health',
        'parent_key' => null,
        'label' => 'Health',
        'visibility_tag' => null,
    ],
    ['key' => 'health_medicines', 'parent_key' => 'health', 'label' => 'Medicines', 'visibility_tag' => null],
    ['key' => 'health_treatment_tests', 'parent_key' => 'health', 'label' => 'Treatment & Tests', 'visibility_tag' => null],
    ['key' => 'health_dental', 'parent_key' => 'health', 'label' => 'Dental', 'visibility_tag' => null],
    ['key' => 'health_other', 'parent_key' => 'health', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'connectivity',
        'parent_key' => null,
        'label' => 'Connectivity',
        'visibility_tag' => null,
    ],
    ['key' => 'connectivity_mobile', 'parent_key' => 'connectivity', 'label' => 'Mobile Plan', 'visibility_tag' => null],
    ['key' => 'connectivity_internet', 'parent_key' => 'connectivity', 'label' => 'Home Internet', 'visibility_tag' => null],
    ['key' => 'connectivity_topups', 'parent_key' => 'connectivity', 'label' => 'Top-ups', 'visibility_tag' => null],
    ['key' => 'connectivity_other', 'parent_key' => 'connectivity', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'subscriptions',
        'parent_key' => null,
        'label' => 'Subscriptions',
        'visibility_tag' => null,
    ],
    ['key' => 'subscriptions_streaming', 'parent_key' => 'subscriptions', 'label' => 'Streaming', 'visibility_tag' => null],
    ['key' => 'subscriptions_apps', 'parent_key' => 'subscriptions', 'label' => 'Apps & Services', 'visibility_tag' => null],
    ['key' => 'subscriptions_cloud', 'parent_key' => 'subscriptions', 'label' => 'Cloud Storage', 'visibility_tag' => null],
    ['key' => 'subscriptions_other', 'parent_key' => 'subscriptions', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'fitness',
        'parent_key' => null,
        'label' => 'Fitness',
        'visibility_tag' => 'fitness',
    ],
    ['key' => 'fitness_gym', 'parent_key' => 'fitness', 'label' => 'Gym', 'visibility_tag' => 'fitness'],
    ['key' => 'fitness_trainer', 'parent_key' => 'fitness', 'label' => 'Trainer', 'visibility_tag' => 'fitness'],
    ['key' => 'fitness_sports', 'parent_key' => 'fitness', 'label' => 'Sports', 'visibility_tag' => 'fitness'],
    ['key' => 'fitness_equipment', 'parent_key' => 'fitness', 'label' => 'Equipment', 'visibility_tag' => 'fitness'],
    ['key' => 'fitness_other', 'parent_key' => 'fitness', 'label' => 'Other', 'visibility_tag' => 'fitness'],

    [
        'key' => 'smoking',
        'parent_key' => null,
        'label' => 'Smoking',
        'visibility_tag' => 'smoking',
    ],
    ['key' => 'smoking_cigarettes', 'parent_key' => 'smoking', 'label' => 'Cigarettes', 'visibility_tag' => 'smoking'],
    ['key' => 'smoking_vape', 'parent_key' => 'smoking', 'label' => 'Vape', 'visibility_tag' => 'smoking'],
    ['key' => 'smoking_other', 'parent_key' => 'smoking', 'label' => 'Other', 'visibility_tag' => 'smoking'],

    [
        'key' => 'pets',
        'parent_key' => null,
        'label' => 'Pets',
        'visibility_tag' => 'pets',
    ],
    ['key' => 'pets_food', 'parent_key' => 'pets', 'label' => 'Food', 'visibility_tag' => 'pets'],
    ['key' => 'pets_vet', 'parent_key' => 'pets', 'label' => 'Vet', 'visibility_tag' => 'pets'],
    ['key' => 'pets_supplies', 'parent_key' => 'pets', 'label' => 'Supplies', 'visibility_tag' => 'pets'],
    ['key' => 'pets_other', 'parent_key' => 'pets', 'label' => 'Other', 'visibility_tag' => 'pets'],

    [
        'key' => 'beauty',
        'parent_key' => null,
        'label' => 'Beauty',
        'visibility_tag' => 'beauty',
    ],
    ['key' => 'beauty_hygiene', 'parent_key' => 'beauty', 'label' => 'Hygiene', 'visibility_tag' => 'beauty'],
    ['key' => 'beauty_cosmetics', 'parent_key' => 'beauty', 'label' => 'Cosmetics', 'visibility_tag' => 'beauty'],
    ['key' => 'beauty_salon', 'parent_key' => 'beauty', 'label' => 'Salon', 'visibility_tag' => 'beauty'],
    ['key' => 'beauty_skincare', 'parent_key' => 'beauty', 'label' => 'Skincare', 'visibility_tag' => 'beauty'],
    ['key' => 'beauty_other', 'parent_key' => 'beauty', 'label' => 'Other', 'visibility_tag' => 'beauty'],

    [
        'key' => 'leisure',
        'parent_key' => null,
        'label' => 'Leisure',
        'visibility_tag' => null,
    ],
    ['key' => 'leisure_food_delivery', 'parent_key' => 'leisure', 'label' => 'Cafes & Delivery', 'visibility_tag' => null],
    ['key' => 'leisure_entertainment', 'parent_key' => 'leisure', 'label' => 'Entertainment', 'visibility_tag' => null],
    ['key' => 'leisure_travel', 'parent_key' => 'leisure', 'label' => 'Travel', 'visibility_tag' => null],
    ['key' => 'leisure_hobbies', 'parent_key' => 'leisure', 'label' => 'Hobbies', 'visibility_tag' => null],
    ['key' => 'leisure_other', 'parent_key' => 'leisure', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'shopping',
        'parent_key' => null,
        'label' => 'Shopping',
        'visibility_tag' => null,
    ],
    ['key' => 'shopping_clothes', 'parent_key' => 'shopping', 'label' => 'Clothes', 'visibility_tag' => null],
    ['key' => 'shopping_shoes', 'parent_key' => 'shopping', 'label' => 'Shoes', 'visibility_tag' => null],
    ['key' => 'shopping_electronics', 'parent_key' => 'shopping', 'label' => 'Electronics', 'visibility_tag' => null],
    ['key' => 'shopping_tools', 'parent_key' => 'shopping', 'label' => 'Tools', 'visibility_tag' => null],
    ['key' => 'shopping_other', 'parent_key' => 'shopping', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'gifts',
        'parent_key' => null,
        'label' => 'Gifts',
        'visibility_tag' => null,
    ],
    ['key' => 'gifts_family', 'parent_key' => 'gifts', 'label' => 'Family', 'visibility_tag' => null],
    ['key' => 'gifts_friends', 'parent_key' => 'gifts', 'label' => 'Friends', 'visibility_tag' => null],
    ['key' => 'gifts_work', 'parent_key' => 'gifts', 'label' => 'Colleagues', 'visibility_tag' => null],
    ['key' => 'gifts_charity', 'parent_key' => 'gifts', 'label' => 'Charity', 'visibility_tag' => null],
    ['key' => 'gifts_other', 'parent_key' => 'gifts', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'work',
        'parent_key' => null,
        'label' => 'Work',
        'visibility_tag' => null,
    ],
    ['key' => 'work_courses', 'parent_key' => 'work', 'label' => 'Courses', 'visibility_tag' => null],
    ['key' => 'work_expenses', 'parent_key' => 'work', 'label' => 'Expenses', 'visibility_tag' => null],
    ['key' => 'work_software', 'parent_key' => 'work', 'label' => 'Software', 'visibility_tag' => null],
    ['key' => 'work_equipment', 'parent_key' => 'work', 'label' => 'Equipment', 'visibility_tag' => null],
    ['key' => 'work_other', 'parent_key' => 'work', 'label' => 'Other', 'visibility_tag' => null],

    [
        'key' => 'finance',
        'parent_key' => null,
        'label' => 'Finance',
        'visibility_tag' => null,
    ],
    ['key' => 'finance_taxes', 'parent_key' => 'finance', 'label' => 'Taxes', 'visibility_tag' => null],
    ['key' => 'finance_insurance', 'parent_key' => 'finance', 'label' => 'Insurance', 'visibility_tag' => 'insurance'],
    ['key' => 'finance_loans', 'parent_key' => 'finance', 'label' => 'Loans', 'visibility_tag' => 'loan'],
    ['key' => 'finance_mortgage', 'parent_key' => 'finance', 'label' => 'Mortgage', 'visibility_tag' => 'mortgage'],
    ['key' => 'finance_fees', 'parent_key' => 'finance', 'label' => 'Fees', 'visibility_tag' => null],
    ['key' => 'finance_other', 'parent_key' => 'finance', 'label' => 'Other', 'visibility_tag' => null],
];
