<?php

return [
    'credit_limit' => env('SUNBITES_CREDIT_LIMIT', 300),

    'loyalty_point_threshold' => env('SUNBITES_LOYALTY_THRESHOLD', 1000),

    'daily_meal_rate' => env('SUNBITES_DAILY_MEAL_RATE', 135),

    'school_months' => [
        'june' => ['label' => 'June', 'days' => 22, 'amount' => 2970],
        'july' => ['label' => 'July', 'days' => 22, 'amount' => 2970],
        'august' => ['label' => 'August', 'days' => 18, 'amount' => 2430],
        'september' => ['label' => 'September', 'days' => 22, 'amount' => 2970],
        'october' => ['label' => 'October', 'days' => 22, 'amount' => 2970],
        'november' => ['label' => 'November', 'days' => 16, 'amount' => 2160],
        'december' => ['label' => 'December', 'days' => 15, 'amount' => 2025],
        'january' => ['label' => 'January', 'days' => 20, 'amount' => 2700],
        'february' => ['label' => 'February', 'days' => 18, 'amount' => 2430],
        'march' => ['label' => 'March', 'days' => 7, 'amount' => 945],
    ],

    'grade_levels' => [
        'Nursery',
        'Kinder 1',
        'Kinder 2',
        'Grade 1',
        'Grade 2',
        'Grade 3',
        'Grade 4',
        'Grade 5',
        'Grade 6',
        'Grade 7',
        'Grade 8',
        'Grade 9',
        'Grade 10',
        'Grade 11',
        'Grade 12',
    ],
];
