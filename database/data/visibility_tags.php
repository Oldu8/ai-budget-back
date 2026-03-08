<?php

/**
 * Visibility tag metadata for funnel questions and settings.
 * Used by funnel/visibility-questions and visibility-tags endpoints.
 * Tag keys must match visibility_tag values in categories.
 */
return [
    [
        'tag' => 'kids',
        'label' => 'Kids',
        'funnel_question_key' => 'has_children',
        'funnel_question_label' => 'Do you have children?',
    ],
    [
        'tag' => 'car',
        'label' => 'Car',
        'funnel_question_key' => 'has_car',
        'funnel_question_label' => 'Do you have a car?',
    ],
    [
        'tag' => 'rent',
        'label' => 'Rent',
        'funnel_question_key' => 'pays_rent',
        'funnel_question_label' => 'Do you pay rent?',
    ],
    [
        'tag' => 'insurance',
        'label' => 'Insurance',
        'funnel_question_key' => 'has_insurance',
        'funnel_question_label' => 'Do you have insurance expenses?',
    ],
    [
        'tag' => 'loan',
        'label' => 'Loans',
        'funnel_question_key' => 'has_loans',
        'funnel_question_label' => 'Do you have loan payments?',
    ],
    [
        'tag' => 'mortgage',
        'label' => 'Mortgage',
        'funnel_question_key' => 'has_mortgage',
        'funnel_question_label' => 'Do you have a mortgage?',
    ],
];
