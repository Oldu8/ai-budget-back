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
        'label' => 'Groceries and household',
        'visibility_tag' => null,
    ],
    ['key' => 'home_food', 'parent_key' => 'home', 'label' => 'Groceries', 'visibility_tag' => null],
    ['key' => 'home_snacks', 'parent_key' => 'home', 'label' => 'Sweets and snacks', 'visibility_tag' => null],
    ['key' => 'home_drinks', 'parent_key' => 'home', 'label' => 'Drinks', 'visibility_tag' => null],
    ['key' => 'home_household_chemicals', 'parent_key' => 'home', 'label' => 'Household chemicals', 'visibility_tag' => null],
    ['key' => 'home_personal_hygiene', 'parent_key' => 'home', 'label' => 'Personal hygiene', 'visibility_tag' => null],
    ['key' => 'home_cosmetics', 'parent_key' => 'home', 'label' => 'Cosmetics', 'visibility_tag' => null],
    ['key' => 'home_other', 'parent_key' => 'home', 'label' => 'Other (home)', 'visibility_tag' => null],

    [
        'key' => 'kids',
        'parent_key' => null,
        'label' => 'Kids',
        'visibility_tag' => 'kids',
    ],
    ['key' => 'kids_clothes', 'parent_key' => 'kids', 'label' => "Kids' clothes", 'visibility_tag' => 'kids'],
    ['key' => 'kids_toys', 'parent_key' => 'kids', 'label' => "Toys and kids' goods", 'visibility_tag' => 'kids'],
    ['key' => 'kids_food', 'parent_key' => 'kids', 'label' => 'Baby food and formula', 'visibility_tag' => 'kids'],
    ['key' => 'kids_care', 'parent_key' => 'kids', 'label' => 'Baby care and hygiene', 'visibility_tag' => 'kids'],
    ['key' => 'kids_other', 'parent_key' => 'kids', 'label' => 'Other (kids)', 'visibility_tag' => 'kids'],

    [
        'key' => 'transport',
        'parent_key' => null,
        'label' => 'Transport',
        'visibility_tag' => null,
    ],
    ['key' => 'transport_taxi', 'parent_key' => 'transport', 'label' => 'Taxi', 'visibility_tag' => null],
    ['key' => 'transport_public', 'parent_key' => 'transport', 'label' => 'Public transport', 'visibility_tag' => null],
    ['key' => 'transport_flights', 'parent_key' => 'transport', 'label' => 'Flights', 'visibility_tag' => null],
    ['key' => 'transport_trains', 'parent_key' => 'transport', 'label' => 'Trains', 'visibility_tag' => null],
    ['key' => 'transport_fuel', 'parent_key' => 'transport', 'label' => 'Fuel', 'visibility_tag' => 'car'],
    ['key' => 'transport_parking', 'parent_key' => 'transport', 'label' => 'Parking', 'visibility_tag' => 'car'],
    ['key' => 'transport_car_service', 'parent_key' => 'transport', 'label' => 'Car maintenance', 'visibility_tag' => 'car'],
    ['key' => 'transport_other', 'parent_key' => 'transport', 'label' => 'Other (transport)', 'visibility_tag' => null],

    [
        'key' => 'housing',
        'parent_key' => null,
        'label' => 'Housing',
        'visibility_tag' => null,
    ],
    ['key' => 'housing_rent', 'parent_key' => 'housing', 'label' => 'Rent', 'visibility_tag' => 'rent'],
    ['key' => 'housing_utilities', 'parent_key' => 'housing', 'label' => 'Utilities', 'visibility_tag' => null],
    ['key' => 'housing_furniture', 'parent_key' => 'housing', 'label' => 'Furniture and home goods', 'visibility_tag' => null],
    ['key' => 'housing_repairs', 'parent_key' => 'housing', 'label' => 'Repairs', 'visibility_tag' => null],
    ['key' => 'housing_other', 'parent_key' => 'housing', 'label' => 'Other (housing)', 'visibility_tag' => null],

    [
        'key' => 'health',
        'parent_key' => null,
        'label' => 'Health',
        'visibility_tag' => null,
    ],
    ['key' => 'health_medicines', 'parent_key' => 'health', 'label' => 'Medicines', 'visibility_tag' => null],
    ['key' => 'health_treatment_tests', 'parent_key' => 'health', 'label' => 'Treatment and tests', 'visibility_tag' => null],
    ['key' => 'health_other', 'parent_key' => 'health', 'label' => 'Other (health)', 'visibility_tag' => null],

    [
        'key' => 'communication',
        'parent_key' => null,
        'label' => 'Communication and internet',
        'visibility_tag' => null,
    ],
    ['key' => 'communication_mobile', 'parent_key' => 'communication', 'label' => 'Mobile phone', 'visibility_tag' => null],
    ['key' => 'communication_internet', 'parent_key' => 'communication', 'label' => 'Internet', 'visibility_tag' => null],
    ['key' => 'communication_subscriptions', 'parent_key' => 'communication', 'label' => 'Subscriptions and services', 'visibility_tag' => null],

    [
        'key' => 'leisure',
        'parent_key' => null,
        'label' => 'Leisure and entertainment',
        'visibility_tag' => null,
    ],
    ['key' => 'leisure_food_delivery', 'parent_key' => 'leisure', 'label' => 'Cafes and delivery', 'visibility_tag' => null],
    ['key' => 'leisure_entertainment', 'parent_key' => 'leisure', 'label' => 'Entertainment', 'visibility_tag' => null],
    ['key' => 'leisure_travel', 'parent_key' => 'leisure', 'label' => 'Travel and vacations', 'visibility_tag' => null],
    ['key' => 'leisure_hobbies', 'parent_key' => 'leisure', 'label' => 'Hobbies', 'visibility_tag' => null],
    ['key' => 'leisure_other', 'parent_key' => 'leisure', 'label' => 'Other (leisure)', 'visibility_tag' => null],

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
    ['key' => 'shopping_other', 'parent_key' => 'shopping', 'label' => 'Other (shopping)', 'visibility_tag' => null],

    [
        'key' => 'family_gifts',
        'parent_key' => null,
        'label' => 'Family and gifts',
        'visibility_tag' => null,
    ],
    ['key' => 'gifts_family', 'parent_key' => 'family_gifts', 'label' => 'Family', 'visibility_tag' => null],
    ['key' => 'gifts_friends', 'parent_key' => 'family_gifts', 'label' => 'Friends', 'visibility_tag' => null],
    ['key' => 'gifts_work', 'parent_key' => 'family_gifts', 'label' => 'Work and colleagues', 'visibility_tag' => null],
    ['key' => 'gifts_charity', 'parent_key' => 'family_gifts', 'label' => 'Charity', 'visibility_tag' => null],
    ['key' => 'gifts_other', 'parent_key' => 'family_gifts', 'label' => 'Other (gifts)', 'visibility_tag' => null],

    [
        'key' => 'work_education',
        'parent_key' => null,
        'label' => 'Work and education',
        'visibility_tag' => null,
    ],
    ['key' => 'work_courses', 'parent_key' => 'work_education', 'label' => 'Courses', 'visibility_tag' => null],
    ['key' => 'work_expenses', 'parent_key' => 'work_education', 'label' => 'Work expenses', 'visibility_tag' => null],
    ['key' => 'work_software', 'parent_key' => 'work_education', 'label' => 'Software', 'visibility_tag' => null],
    ['key' => 'work_other', 'parent_key' => 'work_education', 'label' => 'Other (work)', 'visibility_tag' => null],

    [
        'key' => 'finance_other',
        'parent_key' => null,
        'label' => 'Finance and other',
        'visibility_tag' => null,
    ],
    ['key' => 'finance_taxes', 'parent_key' => 'finance_other', 'label' => 'Taxes', 'visibility_tag' => null],
    ['key' => 'finance_insurance', 'parent_key' => 'finance_other', 'label' => 'Insurance', 'visibility_tag' => 'insurance'],
    ['key' => 'finance_loans', 'parent_key' => 'finance_other', 'label' => 'Loans', 'visibility_tag' => 'loan'],
    ['key' => 'finance_mortgage', 'parent_key' => 'finance_other', 'label' => 'Mortgage', 'visibility_tag' => 'mortgage'],
    ['key' => 'finance_fees', 'parent_key' => 'finance_other', 'label' => 'Fees and commissions', 'visibility_tag' => null],
    ['key' => 'finance_other_misc', 'parent_key' => 'finance_other', 'label' => 'Other (misc)', 'visibility_tag' => null],
];
