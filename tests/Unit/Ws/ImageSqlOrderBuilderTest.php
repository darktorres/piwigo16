<?php

declare(strict_types=1);

use Piwigo\Db\SqlDialect;
use Piwigo\Ws\ImageSqlOrderBuilder;

/**
 * Piwigo\Ws\ImageSqlOrderBuilder -- split out of the former WsHelper
 * god-class (P25 Stage 1 step 6).
 */
test('stdImageSqlOrder returns an empty string for a null/empty/zero order', function (): void {
    $builder = new ImageSqlOrderBuilder();

    expect($builder->stdImageSqlOrder([
        'order' => null,
    ]))->toBe('')
        ->and($builder->stdImageSqlOrder([
            'order' => '',
        ]))->toBe('')
        ->and($builder->stdImageSqlOrder([
            'order' => '0',
        ]))->toBe('');
});

test('stdImageSqlOrder resolves a single known token with its direction', function (): void {
    $builder = new ImageSqlOrderBuilder();

    $result = $builder->stdImageSqlOrder([
        'order' => 'file desc',
    ], 'i.');

    expect($result)
        ->toBe('i.file desc');
});

test('stdImageSqlOrder resolves multiple comma-separated tokens, table-prefixing each', function (): void {
    $builder = new ImageSqlOrderBuilder();

    $result = $builder->stdImageSqlOrder([
        'order' => 'hit asc, id desc',
    ], 'i.');

    expect($result)
        ->toBe('i.hit asc, i.id desc');
});

test('stdImageSqlOrder skips the table prefix for the random field', function (): void {
    $builder = new ImageSqlOrderBuilder();

    $result = $builder->stdImageSqlOrder([
        'order' => 'random',
    ], 'i.');

    // PhotoSortField::Random->column() delegates to
    // SqlDialect::randomFunction(), which returns a seeded RAND(timestamp)
    // under Env::testModeIsActive() rather than a bare RAND() -- reuse
    // that same real call instead of hardcoding the unseeded string.
    expect($result)
        ->toBe(SqlDialect::randomFunction() . ' ');
});

test('stdImageSqlOrder silently ignores an unrecognized token', function (): void {
    $builder = new ImageSqlOrderBuilder();

    $result = $builder->stdImageSqlOrder([
        'order' => 'not_a_real_field',
    ], 'i.');

    expect($result)
        ->toBe('');
});
