<?php

declare(strict_types=1);

use Piwigo\Admin\Install\Projection\InstallRenderPageContext;

test('toArray flattens every fixed property, and omits language_selection/install/errors/infos when null', function (): void {
    $context = new InstallRenderPageContext(
        languageSelection: null,
        languageOptions: ['en_UK' => 'English'],
        tContentEncoding: 'utf-8',
        release: '16.3.0',
        fAction: 'install.php?language=en_UK',
        fDbHost: 'localhost',
        fDbUser: 'root',
        fDbName: 'piwigo',
        fDbPrefix: 'piwigo_',
        fDbDriver: 'mysqli',
        fDbPort: null,
        fAdmin: 'admin',
        fAdminEmail: 'admin@example.test',
        email: '<span class="adminEmail">admin@example.test</span>',
        fNewsletterSubscribe: true,
        lInstallHelp: 'Need help ?',
        install: null,
        errors: null,
        infos: null,
    );

    expect($context->toArray())->toBe([
        'language_options' => ['en_UK' => 'English'],
        'T_CONTENT_ENCODING' => 'utf-8',
        'RELEASE' => '16.3.0',
        'F_ACTION' => 'install.php?language=en_UK',
        'F_DB_HOST' => 'localhost',
        'F_DB_USER' => 'root',
        'F_DB_NAME' => 'piwigo',
        'F_DB_PREFIX' => 'piwigo_',
        'F_DB_DRIVER' => 'mysqli',
        'F_DB_PORT' => null,
        'F_ADMIN' => 'admin',
        'F_ADMIN_EMAIL' => 'admin@example.test',
        'EMAIL' => '<span class="adminEmail">admin@example.test</span>',
        'F_NEWSLETTER_SUBSCRIBE' => true,
        'L_INSTALL_HELP' => 'Need help ?',
    ]);
});

test('toArray includes language_selection/install/errors/infos when set', function (): void {
    $context = new InstallRenderPageContext(
        languageSelection: 'en_UK',
        languageOptions: ['en_UK' => 'English'],
        tContentEncoding: 'utf-8',
        release: '16.3.0',
        fAction: 'install.php?language=en_UK',
        fDbHost: 'localhost',
        fDbUser: 'root',
        fDbName: 'piwigo',
        fDbPrefix: 'piwigo_',
        fDbDriver: 'mysqli',
        fDbPort: 3306,
        fAdmin: 'admin',
        fAdminEmail: 'admin@example.test',
        email: '<span class="adminEmail">admin@example.test</span>',
        fNewsletterSubscribe: false,
        lInstallHelp: 'Need help ?',
        install: true,
        errors: ['enter a login for webmaster'],
        infos: ['Congratulations, Piwigo installation is completed'],
    );

    $result = $context->toArray();

    expect($result['language_selection'])->toBe('en_UK')
        ->and($result['F_DB_PORT'])->toBe(3306)
        ->and($result['install'])->toBeTrue()
        ->and($result['errors'])->toBe(['enter a login for webmaster'])
        ->and($result['infos'])->toBe(['Congratulations, Piwigo installation is completed']);
});
