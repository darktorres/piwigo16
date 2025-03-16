<?php

$themeconf['colorscheme'] = 'clear';

$skin = [
    'BODY' => [
        // REQUIRED
        'backgroundColor' => '#141414',
        // REQUIRED
        'color' => '#bbb',
    ],

    'A' => [
        // REQUIRED
        'color' => '#ddd',
    ],

    'A:hover' => [
        'color' => '#fff',
    ],

    'controls' => [
        'backgroundColor' => 'transparent',
        'color' => 'inherit',
        'border' => '1px solid gray',
    ],

    'controls:focus' => [
        'backgroundColor' => '#3F3F3F',
        'color' => '#fff',
        'boxShadow' => '0 0 2px white',
    ],

    'buttons' => [
        'backgroundColor' => '#0073B2',
        'gradient' => ['#009CDA', '#0073B2'],
        'color' => '#ddd',
        'border' => '1px solid gray',
    ],

    'buttonsHover' => [
        'color' => '#fff',
        'boxShadow' => '0 0 2px white',
    ],

    'menubar' => [
        'backgroundColor' => '#0073B2',
        'gradient' => ['#009CDA', '#0073B2'],
        'color' => '#ddd',
        'link' => [
            'color' => '#fff',
        ],
        //'linkHover'					=> array( 'color' => '#fff' ),
    ],

    'dropdowns' => [
        // REQUIRED - cannot be transparent
        'backgroundColor' => '#2D2D2D',
    ],

    'pageTitle' => [
        'backgroundColor' => '#009CDA',
        'gradient' => ['#0073B2', '#009CDA'],
        'color' => '#ddd',
        'link' => [
            'color' => '#fff',
        ],
        //'linkHover'					=> array( 'color' => '#fff' ),
        'textShadowColor' => '#000',
    ],

    /*'pictureBar' => array(
            'backgroundColor' 	=> '#3F3F3F',
        ),*/

    'widePictureBar' => [
        'backgroundColor' => '#3F3F3F',
    ],

    'pictureWideInfoTable' => [
        'backgroundColor' => '#3F3F3F',
    ],

    'comment' => [
        'backgroundColor' => '#3F3F3F',
    ],

    // should be white or around white
    /*'albumLegend' => array(
            'color' 	=> '#fff',
        ),*/
];
