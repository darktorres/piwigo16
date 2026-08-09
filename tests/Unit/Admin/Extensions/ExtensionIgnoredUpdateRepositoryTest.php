<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateEntity;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateRepository;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;

/**
 * Piwigo\Admin\Extensions\ExtensionIgnoredUpdateRepository -- has a
 * dedicated tests/Integration/ExtensionIgnoredUpdateRepositoryTest.php
 * covering findIgnoredIdsByType()/unignore(); this ports those down to
 * the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern and adds isIgnored()/ignore()/resetType()/resetAll() coverage
 * of its own. `extension_ignored_updates` is empty in the fixture, so
 * every row here is a throwaway insert cleaned up in each test's own
 * finally block.
 */
function extensionIgnoredTestRepo(): ExtensionIgnoredUpdateRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(ExtensionIgnoredUpdateEntity::class);

    return $repo;
}

function extensionIgnoredTestId(string $suffix = ''): string
{
    return 'ut_ext_' . $suffix . bin2hex(random_bytes(6));
}

function extensionIgnoredTestPurge(Connection $conn, ExtensionType $type, string $id): void
{
    $conn->createQueryBuilder()
        ->delete(Tables::extensionIgnoredUpdates())
        ->where('extension_type = :type AND extension_id = :id')
        ->setParameter('type', $type->value)
        ->setParameter('id', $id)
        ->executeStatement();
}

test('isIgnored() is false before ignore() and true after', function (): void {
    $conn = DbConnection::build();
    $id = extensionIgnoredTestId();
    $repo = extensionIgnoredTestRepo();

    try {
        expect($repo->isIgnored(ExtensionType::Plugin, $id))->toBeFalse();

        $repo->ignore(ExtensionType::Plugin, $id, '2026-08-01 12:00:00');

        expect($repo->isIgnored(ExtensionType::Plugin, $id))->toBeTrue();
    } finally {
        extensionIgnoredTestPurge($conn, ExtensionType::Plugin, $id);
    }
});

test('ignore() does not throw when the same extension is ignored twice', function (): void {
    $conn = DbConnection::build();
    $id = extensionIgnoredTestId();
    $repo = extensionIgnoredTestRepo();

    try {
        $repo->ignore(ExtensionType::Theme, $id, '2026-08-01 12:00:00');
        $repo->ignore(ExtensionType::Theme, $id, '2026-08-02 12:00:00');

        expect($repo->findIgnoredIdsByType(ExtensionType::Theme))->toContain($id);
    } finally {
        extensionIgnoredTestPurge($conn, ExtensionType::Theme, $id);
    }
});

test('findIgnoredIdsByType() returns only rows for the requested type', function (): void {
    $conn = DbConnection::build();
    $pluginId = extensionIgnoredTestId('plugin-');
    $themeId = extensionIgnoredTestId('theme-');
    $repo = extensionIgnoredTestRepo();

    try {
        $repo->ignore(ExtensionType::Plugin, $pluginId, '2026-08-01 12:00:00');
        $repo->ignore(ExtensionType::Theme, $themeId, '2026-08-01 12:00:00');

        $pluginIds = $repo->findIgnoredIdsByType(ExtensionType::Plugin);

        expect($pluginIds)->toContain($pluginId)
            ->and($pluginIds)->not->toContain($themeId);
    } finally {
        extensionIgnoredTestPurge($conn, ExtensionType::Plugin, $pluginId);
        extensionIgnoredTestPurge($conn, ExtensionType::Theme, $themeId);
    }
});

test('unignore() removes an existing ignored row', function (): void {
    $conn = DbConnection::build();
    $id = extensionIgnoredTestId();
    $repo = extensionIgnoredTestRepo();

    try {
        $repo->ignore(ExtensionType::Language, $id, '2026-08-01 12:00:00');

        $repo->unignore(ExtensionType::Language, $id);

        expect($repo->isIgnored(ExtensionType::Language, $id))->toBeFalse();
    } finally {
        extensionIgnoredTestPurge($conn, ExtensionType::Language, $id);
    }
});

test('unignore() is a no-op when the row was never ignored', function (): void {
    // Not extensionIgnoredTestRepo() -- its own toBeInstanceOf() assertion
    // would make this "risky" (throwsNoExceptions() expects exactly 0).
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(ExtensionIgnoredUpdateEntity::class);

    $repo->unignore(ExtensionType::Plugin, extensionIgnoredTestId());
})->throwsNoExceptions();

test('resetType() removes every row for that type only, leaving other types untouched', function (): void {
    $conn = DbConnection::build();
    $pluginId = extensionIgnoredTestId('plugin-');
    $themeId = extensionIgnoredTestId('theme-');
    $repo = extensionIgnoredTestRepo();

    try {
        $repo->ignore(ExtensionType::Plugin, $pluginId, '2026-08-01 12:00:00');
        $repo->ignore(ExtensionType::Theme, $themeId, '2026-08-01 12:00:00');

        $repo->resetType(ExtensionType::Plugin);

        expect($repo->isIgnored(ExtensionType::Plugin, $pluginId))->toBeFalse()
            ->and($repo->isIgnored(ExtensionType::Theme, $themeId))->toBeTrue();
    } finally {
        extensionIgnoredTestPurge($conn, ExtensionType::Plugin, $pluginId);
        extensionIgnoredTestPurge($conn, ExtensionType::Theme, $themeId);
    }
});

test('resetAll() removes every row across every type', function (): void {
    $pluginId = extensionIgnoredTestId('plugin-');
    $themeId = extensionIgnoredTestId('theme-');
    $languageId = extensionIgnoredTestId('lang-');
    $repo = extensionIgnoredTestRepo();

    $repo->ignore(ExtensionType::Plugin, $pluginId, '2026-08-01 12:00:00');
    $repo->ignore(ExtensionType::Theme, $themeId, '2026-08-01 12:00:00');
    $repo->ignore(ExtensionType::Language, $languageId, '2026-08-01 12:00:00');

    $repo->resetAll();

    expect($repo->isIgnored(ExtensionType::Plugin, $pluginId))->toBeFalse()
        ->and($repo->isIgnored(ExtensionType::Theme, $themeId))->toBeFalse()
        ->and($repo->isIgnored(ExtensionType::Language, $languageId))->toBeFalse();
});
