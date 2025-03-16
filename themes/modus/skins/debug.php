<?php

/*gradients facebook '#3B5998','#2B4170' ; Google '#E64522','#C33219' ; Pinterest '#CB2027','#A0171C'*/
$skin = [
    'BODY' => [
        // REQUIRED
        'backgroundColor' => '#fff',
        // REQUIRED
        'color' => '#000',
    ],

    'A' => [
        // REQUIRED
        'color' => '#00f',
    ],

    'A:hover' => [
        'color' => '#000',
    ],

    'menubar' => [
        'backgroundColor' => '#C33219',
        'gradient' => ['#E64522', '#C33219'],
        'color' => '#bbb',
        'link' => [
            'color' => '#ddd',
        ],
        'linkHover' => [
            'color' => '#fff',
        ],
    ],

    'dropdowns' => [
        // REQUIRED - cannot be transparent
        'backgroundColor' => '#0f0',
    ],

    'pageTitle' => [
        'backgroundColor' => '#2B4170',
        'gradient' => ['#3B5998', '#2B4170'],
        'color' => '#bbb',
        'link' => [
            'color' => '#ddd',
        ],
        'linkHover' => [
            'color' => '#fff',
        ],
    ],

    'pictureBar' => [
        'backgroundColor' => '#ccc',
    ],

    'widePictureBar' => [
        'backgroundColor' => '#dca',
    ],

    'pictureWideInfoTable' => [
        'backgroundColor' => '#ccc',
    ],

    'comment' => [
        'backgroundColor' => '#ccc',
    ],

    // should be white or around white
    'albumLegend' => [
        'color' => '#fff',
    ],
];
