<?php

declare(strict_types=1);

return [
    'plans' => [
        'free' => [
            'name' => 'Free',
            'tier' => 1,
            'trial_days' => 0,
            'included_seats' => 1,
            'feature_keys' => ['dashboard'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 0,
                    'seat_price_net' => null,
                    'included_usages' => ['tokens' => 0, 'sms' => 0],
                    'usage_overage_prices' => [],
                ],
                'yearly' => [
                    'base_price_net' => 0,
                    'seat_price_net' => null,
                    'included_usages' => ['tokens' => 0, 'sms' => 0],
                    'usage_overage_prices' => [],
                ],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'tier' => 2,
            'trial_days' => 14,
            'included_seats' => 3,
            'feature_keys' => ['dashboard', 'advanced-reports'],
            'allowed_addons' => ['softdrinks'],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900,
                    'seat_price_net' => 990,
                    'included_usages' => ['tokens' => 100, 'sms' => 50],
                    'usage_overage_prices' => ['tokens' => 10, 'sms' => 15],
                ],
                'yearly' => [
                    'base_price_net' => 29000,
                    'seat_price_net' => 9900,
                    'included_usages' => ['tokens' => 1500, 'sms' => 600],
                    'usage_overage_prices' => ['tokens' => 10, 'sms' => 15],
                ],
            ],
        ],
    ],

    'features' => [
        'dashboard' => [
            'name' => 'Dashboard',
            'description' => null,
        ],
        'advanced-reports' => [
            'name' => 'Advanced Reports',
            'description' => null,
        ],
        'softdrinks' => [
            'name' => 'Softdrinks',
            'description' => null,
        ],
    ],

    'addons' => [
        'softdrinks' => [
            'name' => 'Softdrinks',
            'feature_keys' => ['softdrinks'],
            'intervals' => [
                'monthly' => ['price_net' => 490],
                'yearly' => ['price_net' => 4900],
            ],
        ],
    ],
];
