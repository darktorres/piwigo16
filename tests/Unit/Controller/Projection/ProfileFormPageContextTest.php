<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Controller\Projection\ProfileFormPageContext;

test('toArray uses an empty prefix by default, and omits language_selection when null', function (): void {
    $context = new ProfileFormPageContext(
        templatePrefixe: '',
        username: 'jane',
        email: Email::from('jane@example.test'),
        allowUserCustomization: true,
        activateComments: true,
        nbImagePage: 20,
        recentPeriod: 7,
        expand: 'true',
        nbComments: 'false',
        nbHits: 'true',
        redirect: '/index.php',
        fAction: '/profile.php',
        radioOptions: ['true' => 'Yes', 'false' => 'No'],
        templateSelection: ThemeId::from('default'),
        templateOptions: ['default' => 'Default'],
        languageSelection: null,
        languageOptions: ['en_GB' => 'English'],
        specialUser: false,
        inAdmin: false,
        apiCurrentDate: '2026-08-09',
        apiExpiration: [7 => '7 days (August 16, 2026)'],
        apiSelectedExpiration: 7,
        apiCanManage: true,
        apiEmailInfos: 'The email <em>jane@example.test</em> will be used...',
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKey('language_selection')
        ->and($result['USERNAME'])->toBe('jane')
        ->and($result['EMAIL'])->toBe('jane@example.test')
        ->and($result['F_ACTION'])->toBe('/profile.php')
        ->and($result['radio_options'])->toBe(['true' => 'Yes', 'false' => 'No'])
        ->and($result['PWG_TOKEN'])->toBe('abc123');
});

test('toArray prefixes every dynamic key with the GUEST_ prefix, and includes language_selection when set', function (): void {
    $context = new ProfileFormPageContext(
        templatePrefixe: 'GUEST_',
        username: 'guest',
        email: null,
        allowUserCustomization: false,
        activateComments: false,
        nbImagePage: 15,
        recentPeriod: 7,
        expand: 'false',
        nbComments: 'false',
        nbHits: 'false',
        redirect: '',
        fAction: '/admin.php?page=configuration',
        radioOptions: ['true' => 'Yes', 'false' => 'No'],
        templateSelection: ThemeId::from('default'),
        templateOptions: ['default' => 'Default'],
        languageSelection: 'en_GB',
        languageOptions: ['en_GB' => 'English'],
        specialUser: true,
        inAdmin: true,
        apiCurrentDate: '2026-08-09',
        apiExpiration: [],
        apiSelectedExpiration: null,
        apiCanManage: false,
        apiEmailInfos: 'You have no email address...',
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result)->toHaveKeys(['GUEST_USERNAME', 'GUEST_EMAIL', 'GUEST_F_ACTION'])
        ->and($result)->not->toHaveKeys(['USERNAME', 'EMAIL', 'F_ACTION'])
        ->and($result['GUEST_USERNAME'])->toBe('guest')
        ->and($result['SPECIAL_USER'])->toBeTrue()
        ->and($result['language_selection'])->toBe('en_GB');
});
