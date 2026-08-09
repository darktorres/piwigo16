<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\SqlDate;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\SqlTime;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Db\Type\CategoryIdType;
use Piwigo\Db\Type\CommentIdType;
use Piwigo\Db\Type\EmailType;
use Piwigo\Db\Type\GracefulIpAddressType;
use Piwigo\Db\Type\GracefulSqlDateTimeType;
use Piwigo\Db\Type\GroupIdType;
use Piwigo\Db\Type\IpAddressType;
use Piwigo\Db\Type\LangCodeType;
use Piwigo\Db\Type\PermalinkType;
use Piwigo\Db\Type\PluginIdType;
use Piwigo\Db\Type\SqlDateType;
use Piwigo\Db\Type\SqlTimeType;
use Piwigo\Db\Type\TagIdType;
use Piwigo\Db\Type\ThemeIdType;
use Piwigo\Db\Type\UserIdType;
use Piwigo\Db\Type\UsernameType;

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

test('GracefulSqlDateTimeType converts a real DB string to a SqlDateTime and back', function (): void {
    $type = new GracefulSqlDateTimeType();
    $platform = new MySQLPlatform();

    expect($type->convertToPHPValue('2026-08-01 12:00:00', $platform))->toEqual(SqlDateTime::from('2026-08-01 12:00:00'));
    expect($type->convertToPHPValue(null, $platform))->toBeNull();

    expect($type->convertToDatabaseValue(SqlDateTime::from('2026-08-01 12:00:00'), $platform))->toBe('2026-08-01 12:00:00');
    expect($type->convertToDatabaseValue(null, $platform))->toBeNull();
});

test('GracefulSqlDateTimeType returns null instead of throwing for a MySQL zero-date sentinel', function (): void {
    $type = new GracefulSqlDateTimeType();

    expect($type->convertToPHPValue('0000-00-00 00:00:00', new MySQLPlatform()))->toBeNull();
});

test('GracefulSqlDateTimeType returns null instead of throwing for any other unparseable non-null value', function (): void {
    $type = new GracefulSqlDateTimeType();

    expect($type->convertToPHPValue('not-a-date', new MySQLPlatform()))->toBeNull();
    expect($type->convertToPHPValue('2026-02-30 00:00:00', new MySQLPlatform()))->toBeNull();
});

test('GracefulSqlDateTimeType rejects a non-string value from the DB driver', function (): void {
    $type = new GracefulSqlDateTimeType();

    $type->convertToPHPValue(12345, new MySQLPlatform());
})->throws(InvalidArgumentException::class, 'Expected string from the DB driver, got int');

test('GracefulSqlDateTimeType rejects a non-VO value being written to the DB', function (): void {
    $type = new GracefulSqlDateTimeType();

    $type->convertToDatabaseValue('2026-08-01 12:00:00', new MySQLPlatform());
})->throws(InvalidArgumentException::class);

test('the other AbstractStringVoType subclasses each wire to their own real VO class', function (): void {
    $platform = new MySQLPlatform();

    expect(new EmailType()->convertToPHPValue('user@example.com', $platform))->toEqual(Email::from('user@example.com'));
    expect(new LangCodeType()->convertToPHPValue('en_US', $platform))->toEqual(LangCode::from('en_US'));
    expect(new PermalinkType()->convertToPHPValue('my-album', $platform))->toEqual(Permalink::from('my-album'));
    expect(new PluginIdType()->convertToPHPValue('my_plugin', $platform))->toEqual(PluginId::from('my_plugin'));
    expect(new SqlDateType()->convertToPHPValue('2026-08-01', $platform))->toEqual(SqlDate::from('2026-08-01'));
    expect(new SqlTimeType()->convertToPHPValue('12:30:00', $platform))->toEqual(SqlTime::from('12:30:00'));
    expect(new ThemeIdType()->convertToPHPValue('my_theme', $platform))->toEqual(ThemeId::from('my_theme'));
    expect(new UsernameType()->convertToPHPValue('alice', $platform))->toEqual(Username::from('alice'));

    expect(new EmailType()->convertToDatabaseValue(Email::from('user@example.com'), $platform))->toBe('user@example.com');
    expect(new LangCodeType()->convertToDatabaseValue(LangCode::from('en_US'), $platform))->toBe('en_US');
    expect(new PermalinkType()->convertToDatabaseValue(Permalink::from('my-album'), $platform))->toBe('my-album');
    expect(new PluginIdType()->convertToDatabaseValue(PluginId::from('my_plugin'), $platform))->toBe('my_plugin');
    expect(new SqlDateType()->convertToDatabaseValue(SqlDate::from('2026-08-01'), $platform))->toBe('2026-08-01');
    expect(new SqlTimeType()->convertToDatabaseValue(SqlTime::from('12:30:00'), $platform))->toBe('12:30:00');
    expect(new ThemeIdType()->convertToDatabaseValue(ThemeId::from('my_theme'), $platform))->toBe('my_theme');
    expect(new UsernameType()->convertToDatabaseValue(Username::from('alice'), $platform))->toBe('alice');
});

test('GracefulIpAddressType round-trips the empty-string sentinel to/from null instead of throwing', function (): void {
    $type = new GracefulIpAddressType();
    $platform = new MySQLPlatform();

    expect($type->convertToPHPValue('', $platform))->toBeNull();
    expect($type->convertToDatabaseValue(null, $platform))->toBe('');
});

test('GracefulIpAddressType converts a real DB value to an IpAddress and back', function (): void {
    $type = new GracefulIpAddressType();
    $platform = new MySQLPlatform();

    expect($type->convertToPHPValue('192.168.1.1', $platform))->toEqual(IpAddress::from('192.168.1.1'));
    expect($type->convertToDatabaseValue(IpAddress::from('192.168.1.1'), $platform))->toBe('192.168.1.1');
});

test('GracefulIpAddressType rtrims a CHAR(39)-padded value before parsing (history.IP real driver shape)', function (): void {
    $type = new GracefulIpAddressType();
    $platform = new MySQLPlatform();

    $padded = str_pad('192.168.1.1', 39);
    expect($type->convertToPHPValue($padded, $platform))->toEqual(IpAddress::from('192.168.1.1'));

    $paddedSentinel = str_pad('', 39);
    expect($type->convertToPHPValue($paddedSentinel, $platform))->toBeNull();
});

test('GracefulIpAddressType still throws on a genuinely malformed, non-empty value', function (): void {
    $type = new GracefulIpAddressType();

    $type->convertToPHPValue('not-an-ip', new MySQLPlatform());
})->throws(InvalidArgumentException::class, "Invalid IP address: 'not-an-ip'");
