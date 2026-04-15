<?php

declare(strict_types=1);

return [
    'plans' => [
        'free' => [
            'name' => 'Free',
            'tier' => 1,
            'trial_days' => 0,
            'included_seats' => 1,
            'included_usages' => [],
            'feature_keys' => ['dashboard'],
            'allowed_addons' => [],
            'intervals' => [
                'monthly' => ['base_price_net' => 0, 'seat_price_net' => null],
                'yearly' => ['base_price_net' => 0, 'seat_price_net' => null],
            ],
        ],
    ],

    'addons' => [
        // 'print-gateway' => [
        //     'name' => 'Print Gateway',
        //     'feature_keys' => ['print-gateway'],
        //     'intervals' => [
        //         'monthly' => ['price_net' => 990],
        //         'yearly' => ['price_net' => 9900],
        //     ],
        // ],
    ],
];
