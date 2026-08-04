<?php

declare(strict_types=1);

return [
    // Each plan may set `hidden => true` to keep it out of everything a customer
    // chooses from — the checkout plan step and the portal's plan-change screen.
    // Use it for internal, supplier, grandfathered and legacy plans.
    //
    // Hidden means unlisted, never unresolvable: lookups by code keep working, so
    // a subscriber on a hidden plan still sees its name, features, seats and quota
    // in the portal, and `ActivateLocalSubscription` can assign it programmatically.
    // Moving *onto* a hidden plan from outside is refused server-side with
    // `HiddenPlanNotSelectableException`; staying on one and switching interval
    // within it stay allowed. At least one plan must remain visible —
    // `billing:check-config` errors otherwise.
    'plans' => [
        'free' => [
            'name' => 'Free',
            'description' => null,
            'hidden' => false,
            'tier' => 1,
            'included_seats' => 1,
            'feature_keys' => ['dashboard'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 0,
                    'seat_price_net' => null,
                    'included_usages' => ['Tokens' => 0, 'SMS' => 0],
                    // A quota with no overage price cannot be charged for overuse.
                    // When that is deliberate, declare it as `'Tokens' => false`
                    // instead of omitting the key — otherwise `billing:check-config`
                    // cannot tell the intent apart from a forgotten price. Both
                    // behave identically at runtime.
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
            'included_seats' => 3,
            'feature_keys' => ['dashboard', 'advanced-reports'],
            'allowed_addons' => ['softdrinks'],
            // Trial periods are configured per (plan, interval) under
            // `intervals.{monthly|yearly}.trial_days`. Setting it at plan
            // root is no longer supported and CheckConfigCommand will fail.
            'intervals' => [
                'monthly' => [
                    'base_price_net' => 2900,
                    'seat_price_net' => 990,
                    'trial_days' => 14,
                    'included_usages' => ['Tokens' => 100, 'SMS' => 50],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                ],
                'yearly' => [
                    'base_price_net' => 29000,
                    'seat_price_net' => 9900,
                    'trial_days' => 14,
                    'included_usages' => ['Tokens' => 1500, 'SMS' => 600],
                    'usage_overage_prices' => ['Tokens' => 10, 'SMS' => 15],
                ],
            ],
        ],
    ],

    // Per-usage-type configuration. Each usage type referenced in
    // `plans.*.intervals.*.included_usages` / `usage_overage_prices`
    // can opt in or out of rollover here. Types not listed fall back
    // to `mollie-billing.usage_rollover_fallback`.
    'usage_types' => [
        'Tokens' => ['rollover' => true],
        'SMS' => ['rollover' => false],
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
