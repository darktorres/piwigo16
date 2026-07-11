<?php

declare(strict_types=1);

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ValidationPattern;

test('AppInfo carries the 4 retired PHPWG_* constants', function (): void {
    expect(AppInfo::VERSION)->toBe('16.3.0')
        ->and(AppInfo::DEFAULT_LANGUAGE)->toBe('en_UK')
        ->and(AppInfo::DEFAULT_TEMPLATE)->toBe('modus')
        ->and(AppInfo::REQUIRED_PHP_VERSION)->toBe('8.5.0');
});

test('AccessLevel matches the origin ACCESS_* ordering exactly', function (): void {
    expect(AccessLevel::Free)->toBe(0)
        ->and(AccessLevel::Guest)->toBe(1)
        ->and(AccessLevel::Classic)->toBe(2)
        ->and(AccessLevel::Administrator)->toBe(3)
        ->and(AccessLevel::Webmaster)->toBe(4)
        ->and(AccessLevel::Closed)->toBe(5);
});

test('ActivitySystem matches the origin ACTIVITY_* ordering exactly', function (): void {
    expect(ActivitySystem::Core)->toBe(1)
        ->and(ActivitySystem::Plugin)->toBe(2)
        ->and(ActivitySystem::Theme)->toBe(3);
});

test('ValidationPattern::ID matches a plain positive integer only', function (): void {
    expect(preg_match(ValidationPattern::ID, '42'))->toBe(1)
        ->and(preg_match(ValidationPattern::ID, '-42'))->toBe(0)
        ->and(preg_match(ValidationPattern::ID, 'abc'))->toBe(0);
});

test('ValidationPattern::ORDER matches real ORDER BY clause shapes', function (): void {
    expect(preg_match(ValidationPattern::ORDER, 'name asc'))->toBe(1)
        ->and(preg_match(ValidationPattern::ORDER, 'random'))->toBe(1)
        ->and(preg_match(ValidationPattern::ORDER, 'name, date desc'))->toBe(1)
        ->and(preg_match(ValidationPattern::ORDER, 'DROP TABLE users'))->toBe(0);
});
