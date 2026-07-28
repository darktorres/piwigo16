<?php

declare(strict_types=1);

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
$envVars = ['PIWIGO_DB_PREFIX'];
$originalEnvVars = [];

beforeEach(function () use ($envVars, &$originalEnvVars): void {
    foreach ($envVars as $var) {
        $value = getenv($var);
        $originalEnvVars[$var] = $value === false ? null : $value;
        putenv($var);
    }
    DbCredentials::reset();
});

afterEach(function () use ($envVars, &$originalEnvVars): void {
    foreach ($envVars as $var) {
        putenv($originalEnvVars[$var] === null ? $var : $var . '=' . $originalEnvVars[$var]);
    }
    DbCredentials::reset();
});

test('loadClassMetadata prefixes a bare table name', function (): void {
    putenv('PIWIGO_DB_PREFIX=piwigo_');
    DbCredentials::reset();

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));

    (new TablePrefixListener())->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('piwigo_sessions');
});

test('loadClassMetadata leaves the table name untouched when the configured prefix is empty', function (): void {
    // DbCredentials::env()'s own fallback treats an empty-string env var
    // the same as unset (falls back to the 'piwigo_' default) -- confirmed
    // live, so putenv('PIWIGO_DB_PREFIX=') alone can never produce a
    // genuinely empty prefix. Constructing DbCredentials directly and
    // injecting it via reflection is the real way to reach this branch.
    $credentials = new DbCredentials(
        host: 'localhost',
        user: '',
        password: '',
        database: '',
        prefix: '',
    );
    new ReflectionProperty(DbCredentials::class, 'current')->setValue(null, $credentials);

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));

    (new TablePrefixListener())->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('sessions');
});

test('loadClassMetadata leaves an already-prefixed table name untouched (idempotent re-processing)', function (): void {
    putenv('PIWIGO_DB_PREFIX=piwigo_');
    DbCredentials::reset();

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'piwigo_sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));

    (new TablePrefixListener())->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('piwigo_sessions');
});

test('loadClassMetadata applies a custom, non-default prefix', function (): void {
    putenv('PIWIGO_DB_PREFIX=demo17_');
    DbCredentials::reset();

    $metadata = new ClassMetadata('Piwigo\\Tests\\Fixtures\\FakeEntity');
    $metadata->setPrimaryTable(['name' => 'sessions']);
    $args = new LoadClassMetadataEventArgs($metadata, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));

    (new TablePrefixListener())->loadClassMetadata($args);

    expect($metadata->getTableName())->toBe('demo17_sessions');
});
