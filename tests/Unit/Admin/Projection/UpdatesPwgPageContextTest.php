<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UpdatesPwgPageContext;

test('toArray flattens every fixed property, and omits the 12 optional keys when null', function (): void {
    $context = new UpdatesPwgPageContext(
        containerVersion: null,
        dockerUpdateGuideUrl: null,
        checkVersion: null,
        devVersion: null,
        missing: null,
        minorReleasePhpRequired: null,
        majorReleasePhpRequired: null,
        step: 0,
        piwigoCurrentVersion: '16.3.0',
        upgradeTo: '',
        pwgToken: 'abc123',
        minorVersion: null,
        minorReleaseUrl: null,
        majorVersion: null,
        majorReleaseUrl: null,
        majorDockerReleaseUrl: null,
        majorVersionPwg: null,
        adminPageTitle: 'Updates',
    );

    expect($context->toArray())
        ->toBe([
            'STEP' => 0,
            'PIWIGO_CURRENT_VERSION' => '16.3.0',
            'UPGRADE_TO' => '',
            'PWG_TOKEN' => 'abc123',
            'ADMIN_PAGE_TITLE' => 'Updates',
        ]);
});

test('toArray includes every optional key when set', function (): void {
    $context = new UpdatesPwgPageContext(
        containerVersion: '1.2.3',
        dockerUpdateGuideUrl: 'https://piwigo.example/guide-update-docker',
        checkVersion: true,
        devVersion: false,
        missing: [
            'plugins' => [],
        ],
        minorReleasePhpRequired: '8.4.0',
        majorReleasePhpRequired: '8.5.0',
        step: 3,
        piwigoCurrentVersion: '16.3.0',
        upgradeTo: '17.0.0',
        pwgToken: 'abc123',
        minorVersion: '16.4.0',
        minorReleaseUrl: 'https://piwigo.example/releases/16.4.0',
        majorVersion: '17.0.0',
        majorReleaseUrl: 'https://piwigo.example/releases/17.0.0',
        majorDockerReleaseUrl: 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#1700',
        majorVersionPwg: '17.0.0',
        adminPageTitle: 'Updates',
    );

    expect($context->toArray())
        ->toBe([
            'STEP' => 3,
            'PIWIGO_CURRENT_VERSION' => '16.3.0',
            'UPGRADE_TO' => '17.0.0',
            'PWG_TOKEN' => 'abc123',
            'ADMIN_PAGE_TITLE' => 'Updates',
            'CONTAINER_VERSION' => '1.2.3',
            'DOCKER_UPDATE_GUIDE_URL' => 'https://piwigo.example/guide-update-docker',
            'CHECK_VERSION' => true,
            'DEV_VERSION' => false,
            'missing' => [
                'plugins' => [],
            ],
            'MINOR_RELEASE_PHP_REQUIRED' => '8.4.0',
            'MAJOR_RELEASE_PHP_REQUIRED' => '8.5.0',
            'MINOR_VERSION' => '16.4.0',
            'MINOR_RELEASE_URL' => 'https://piwigo.example/releases/16.4.0',
            'MAJOR_VERSION' => '17.0.0',
            'MAJOR_RELEASE_URL' => 'https://piwigo.example/releases/17.0.0',
            'MAJOR_DOCKER_RELEASE_URL' => 'https://github.com/Piwigo/piwigo-docker/wiki/Changelog#1700',
            'MAJOR_VERSION_PWG' => '17.0.0',
        ]);
});
