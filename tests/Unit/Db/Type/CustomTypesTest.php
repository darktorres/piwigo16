<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\Type\CategoryIdType;
use Piwigo\Db\Type\CommentIdType;
use Piwigo\Db\Type\GroupIdType;
use Piwigo\Db\Type\IpAddressType;
use Piwigo\Db\Type\TagIdType;
use Piwigo\Db\Type\UserIdType;

/**
 * Piwigo\Db\Type\* -- the Doctrine custom Type classes mapping VOs
 * directly onto DB columns, had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1). Their
 * *happy-path* conversion logic is already exercised on essentially every
 * ORM query touching a UserId/CommentId/GroupId/CategoryId/TagId/
 * IpAddress-typed column across the whole Integration suite -- this
 * targets the defensive type-check branches real DB reads and real
 * application code never trigger (a DB driver always returns int|string
 * for a numeric column and a real VO is always passed for a write), plus
 * the getSQLDeclaration()/getBindingType() pair no caller directly
 * exercises either.
 */
test('UserIdType converts a real DB int/string to a UserId and back', function (): void {
    $type = new UserIdType();
    $platform = new MySQLPlatform();

    expect($type->convertToPHPValue(5, $platform))->toEqual(UserId::from(5));
    expect($type->convertToPHPValue('5', $platform))->toEqual(UserId::from(5));
    expect($type->convertToPHPValue(null, $platform))->toBeNull();

    expect($type->convertToDatabaseValue(UserId::from(5), $platform))->toBe(5);
    expect($type->convertToDatabaseValue(null, $platform))->toBeNull();
});

test('UserIdType rejects a non-int/string value from the DB driver', function (): void {
    $type = new UserIdType();

    $type->convertToPHPValue(['not' => 'scalar'], new MySQLPlatform());
})->throws(InvalidArgumentException::class, 'Expected int or string from the DB driver, got array');

test('UserIdType rejects a non-VO value being written to the DB', function (): void {
    $type = new UserIdType();

    $type->convertToDatabaseValue('5', new MySQLPlatform());
})->throws(InvalidArgumentException::class);

test('UserIdType declares an integer SQL column and binding type', function (): void {
    $type = new UserIdType();
    $platform = new MySQLPlatform();

    expect($type->getSQLDeclaration(['unsigned' => true], $platform))
        ->toBe($platform->getIntegerTypeDeclarationSQL(['unsigned' => true]));
    expect($type->getBindingType())->toBe(ParameterType::INTEGER);
});

test('the other numeric id types each wire to their own real VO class', function (): void {
    $platform = new MySQLPlatform();

    expect(new CommentIdType()->convertToPHPValue(7, $platform))->toEqual(CommentId::from(7));
    expect(new GroupIdType()->convertToPHPValue(7, $platform))->toEqual(GroupId::from(7));
    expect(new CategoryIdType()->convertToPHPValue(7, $platform))->toEqual(CategoryId::from(7));
    expect(new TagIdType()->convertToPHPValue(7, $platform))->toEqual(TagId::from(7));

    expect(new CommentIdType()->convertToDatabaseValue(CommentId::from(7), $platform))->toBe(7);
    expect(new GroupIdType()->convertToDatabaseValue(GroupId::from(7), $platform))->toBe(7);
    expect(new CategoryIdType()->convertToDatabaseValue(CategoryId::from(7), $platform))->toBe(7);
    expect(new TagIdType()->convertToDatabaseValue(TagId::from(7), $platform))->toBe(7);
});

test('IpAddressType converts a real DB string to an IpAddress and back', function (): void {
    $type = new IpAddressType();
    $platform = new MySQLPlatform();

    expect($type->convertToPHPValue('192.168.1.1', $platform))->toEqual(IpAddress::from('192.168.1.1'));
    expect($type->convertToPHPValue(null, $platform))->toBeNull();

    expect($type->convertToDatabaseValue(IpAddress::from('192.168.1.1'), $platform))->toBe('192.168.1.1');
    expect($type->convertToDatabaseValue(null, $platform))->toBeNull();
});

test('IpAddressType rejects a non-string value from the DB driver', function (): void {
    $type = new IpAddressType();

    $type->convertToPHPValue(12345, new MySQLPlatform());
})->throws(InvalidArgumentException::class, 'Expected string from the DB driver, got int');

test('IpAddressType rejects a non-VO value being written to the DB', function (): void {
    $type = new IpAddressType();

    $type->convertToDatabaseValue('192.168.1.1', new MySQLPlatform());
})->throws(InvalidArgumentException::class);

test('IpAddressType declares a string SQL column and binding type', function (): void {
    $type = new IpAddressType();
    $platform = new MySQLPlatform();

    expect($type->getSQLDeclaration(['length' => 45], $platform))
        ->toBe($platform->getStringTypeDeclarationSQL(['length' => 45]));
    expect($type->getBindingType())->toBe(ParameterType::STRING);
});
