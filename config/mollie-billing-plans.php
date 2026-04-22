<?php

declare(strict_types=1);

return [
    'plans' => [
        'free' => [
            'name' => 'Free',
            'description' => null,
            'tier' => 1,
            'trial_days' => 0,
            'included_seats' => 1,
            'feature_keys' => ['dashboard'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 0,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 0, 'SMS' => 0],
                    'usage_overage_prices' => [],
                ],
                'yearly' => [
                    'base_price_net' => 0,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 0, 'SMS' => 0],
                    'usage_overage_prices' => [],
                ],
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'description' => null,
            'tier' => 2,
            'trial_days' => 14,
            'included_seats' => 3,
            'feature_keys' => ['dashboard', 'advanced-reports'],
            'allowed_addons' => ['softdrinks'],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900,
                    'seat_price_net' => 990,
                    'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                ],
                'yearly' => [
                    'base_price_net' => 29000,
                    'seat_price_net' => 9900,
                    'included_usages' => ['Tokens' => 1500, 'SMS' => 600],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
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

    'product_groups' => [
        // 'top-ups'  => ['name' => 'Top-Ups',  'sort' => 1],
        // 'services' => ['name' => 'Services', 'sort' => 2],
    ],

    'products' => [
        // 'token-pack-500' => [
        //     'name' => '500 Token Pack',
        //     'description' => 'Top up your account with 500 tokens.',
        //     'image_url' => null,
        //     'price_net' => 4900,
        //     'usage_type' => 'Tokens',    // optional — links to wallet
        //     'quantity' => 500,            // optional — units to credit on purchase
        //     'group' => 'top-ups',         // optional — references key in product_groups
        // ],
        // 'consulting-hour' => [
        //     'name' => '1h Consulting',
        //     'description' => 'Book a one-hour consulting session.',
        //     'image_url' => null,
        //     'price_net' => 14900,
        //     'onetimeonly' => true,        // can only be purchased once (default: false)
        //     'group' => 'services',
        // ],
    ],
];
