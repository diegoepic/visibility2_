<?php


return [

    'google_maps_api_key' => 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw',
    'map_defaults' => [
        'center' => [
            'lat' => -33.45,
            'lng' => -70.66
        ],
        'zoom' => 5,
        'max_zoom' => 18,
        'min_zoom' => 4,
    ],

    'chile_bounds' => [
        'southwest' => [
            'lat' => -56.0,
            'lng' => -76.0
        ],
        'northeast' => [
            'lat' => -17.5,
            'lng' => -66.0
        ]
    ],

    'marker_base_url' => '/visibility2/portal/assets/images/',
    'marker_icons' => [
        'priority' => 'marker_blue1.png',
        'normal' => 'marker_red1.png',
    ],
];
