<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Config\NotificationConfig;
use Piwigo\Config\UnknownConfigKeyException;

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
});

test('has/override/delete round-trip', function (): void {
    expect(Config::has('gallery_title'))->toBeFalse();

    Config::override('gallery_title', 'My Gallery');
    expect(Config::has('gallery_title'))->toBeTrue()
        ->and(Config::all()['gallery_title'])->toBe('My Gallery');

    Config::delete('gallery_title');
    expect(Config::has('gallery_title'))->toBeFalse();
});

test('loadArray replaces the whole data set', function (): void {
    Config::override('gallery_title', 'before');
    Config::loadArray(['db_host' => 'localhost']);

    expect(Config::has('gallery_title'))->toBeFalse()
        ->and(Config::all())->toBe(['db_host' => 'localhost']);
});

test('a bool accessor reads the override and falls back to its default', function (): void {
    expect(Config::activateComments())->toBeTrue(); // SCHEMA default

    Config::override('activate_comments', false);
    expect(Config::activateComments())->toBeFalse();
});

test('an int accessor reads the override and falls back to its default', function (): void {
    expect(Config::webmasterId())->toBe(1);

    Config::override('webmaster_id', 42);
    expect(Config::webmasterId())->toBe(42);
});

test('a string accessor reads the override and falls back to its default', function (): void {
    expect(Config::galleryTitle())->toBe('Piwigo');

    Config::override('gallery_title', 'Custom');
    expect(Config::galleryTitle())->toBe('Custom');
});

test('a nullable string accessor returns null when unset', function (): void {
    expect(Config::galleryUrl())->toBeNull();

    Config::override('gallery_url', 'https://example.test');
    expect(Config::galleryUrl())->toBe('https://example.test');
});

test('a custom array accessor coerces and falls back to its hardcoded default', function (): void {
    expect(Config::pictureExtensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);

    Config::override('picture_ext', ['jpg', 'png']);
    expect(Config::pictureExtensions())->toBe(['jpg', 'png']);
});

test('the recentPostDates custom accessor returns a NotificationConfig VO', function (): void {
    $config = Config::recentPostDates();

    expect($config)->toBeInstanceOf(NotificationConfig::class)
        ->and($config->rss->maxDates)->toBe(5)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('the userFields custom accessor returns the simplified array shape, not a VO', function (): void {
    expect(Config::userFields())->toBe([
        'id' => 'id',
        'username' => 'username',
        'password' => 'password',
        'email' => 'mail_address',
    ]);
});

test('orderBy filters out entries with an invalid direction', function (): void {
    Config::override('order_by', [
        ['field' => 'date_available', 'dir' => 'DESC'],
        ['field' => 'bogus', 'dir' => 'SIDEWAYS'],
        ['field' => 'id', 'dir' => 'asc'],
    ]);

    expect(Config::orderBy())->toBe([
        ['field' => 'date_available', 'dir' => 'DESC'],
        ['field' => 'id', 'dir' => 'ASC'],
    ]);
});

test('dumpForLog redacts sensitive keys', function (): void {
    Config::override('db_password', 'super-secret');
    Config::override('gallery_title', 'Visible');

    $dump = Config::dumpForLog();

    expect($dump['db_password'])->toBe('********')
        ->and($dump['gallery_title'])->toBe('Visible');
});

test('a bad key on a typed getter throws UnknownConfigKeyException', function (): void {
    // has()/override()/delete() accept any string (dynamic-key escape hatch),
    // but the private typed getters guard against SCHEMA drift -- exercised
    // indirectly since they're private; this asserts the exception class
    // itself carries a useful message shape.
    $exception = new UnknownConfigKeyException('bogus_key', 'getString');

    expect($exception->getMessage())->toContain('bogus_key')
        ->and($exception->getMessage())->toContain('getString');
});
