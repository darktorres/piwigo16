<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\TablePrefixListener;

/**
 * The apply-the-prefix path is already exercised end to end by every real
 * ORM setup in this suite (ConfigRepositoryTest, TelemetryServiceTest,
 * SchemaParityTest, EntityManagerSmokeTest all register this listener
 * against real attribute-mapped entities). What none of those exercise is
 * the early-return guard: an empty configured prefix, and a table name
 * that has already been prefixed (idempotent re-processing) -- both
 * covered here directly against a bare ClassMetadata, with a PHPUnit stub
 * EntityManagerInterface since loadClassMetadata() never calls
 * getObjectManager().
 */
test('loadClassMetadata prefixes a bare table name', function (): void {
    $credentials = new DbCredentials(host: 'localhost', user: '', password: '', database: '', prefix: 'piwigo_');

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));

    new TablePrefixListener($credentials)->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('piwigo_sessions');
});

test('loadClassMetadata leaves the table name untouched when the configured prefix is empty', function (): void {
    // singleton/service-locator elimination campaign, Phase 3:
    // TablePrefixListener takes DbCredentials via real constructor
    // injection now -- constructing one directly with an empty prefix is
    // the real way to reach this branch, no reflection needed anymore.
    $credentials = new DbCredentials(
        host: 'localhost',
        user: '',
        password: '',
        database: '',
        prefix: '',
    );

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));

    new TablePrefixListener($credentials)->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('sessions');
});

test('loadClassMetadata leaves an already-prefixed table name untouched (idempotent re-processing)', function (): void {
    $credentials = new DbCredentials(host: 'localhost', user: '', password: '', database: '', prefix: 'piwigo_');

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'piwigo_sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));

    new TablePrefixListener($credentials)->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('piwigo_sessions');
});

test('loadClassMetadata applies a custom, non-default prefix', function (): void {
    $credentials = new DbCredentials(host: 'localhost', user: '', password: '', database: '', prefix: 'demo17_');

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));

    new TablePrefixListener($credentials)->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('demo17_sessions');
});

/**
 * Confirmed-equivalent: line 29's EmptyStringToNotEmpty on the `$prefix
 * === ''` guard. str_starts_with($tableName, '') is always true for any
 * $tableName (an empty needle always matches) -- the chain's own SECOND
 * disjunct already independently returns early for an empty prefix, on
 * its own, regardless of what the first disjunct's literal is mutated
 * to. Confirmed live: the full suite in this file passes identically
 * with the mutation applied.
 */
