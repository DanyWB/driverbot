<?php

return [
    'rounding_step' => 50,

    'templates' => [
        'click' => [
            'label' => 'Light scooters / Click',
            'vehicle_type' => 'scooter',
            'coefficients' => [
                '1d' => '1',
                '7d' => '0.9937',
                '14d' => '0.9316',
                '21d' => '0.8281',
                'month' => '0.6376',
            ],
        ],
        'aerox' => [
            'label' => 'Comfort scooters / Aerox',
            'vehicle_type' => 'scooter',
            'coefficients' => [
                '1d' => '1',
                '7d' => '0.9047',
                '14d' => '0.7857',
                '21d' => '0.6984',
                'month' => '0.5555',
            ],
        ],
        'adv160' => [
            'label' => 'ADV 160',
            'vehicle_type' => 'scooter',
            'coefficients' => [
                '1d' => '1',
                '7d' => '0.8888',
                '14d' => '0.7777',
                '21d' => '0.7407',
                'month' => '0.59255',
            ],
        ],
        'pcx160' => [
            'label' => 'PCX 160',
            'vehicle_type' => 'scooter',
            'coefficients' => [
                '1d' => '1',
                '7d' => '0.96',
                '14d' => '0.888',
                '21d' => '0.777',
                'month' => '0.69',
            ],
        ],
        'cars' => [
            'label' => 'Cars',
            'vehicle_type' => 'car',
            'coefficients' => [
                '1d' => '1',
                '7d' => '0.8888',
                '14d' => '0.7777',
                '21d' => '0.6666',
                'month' => '0.5555',
            ],
        ],
    ],
];
