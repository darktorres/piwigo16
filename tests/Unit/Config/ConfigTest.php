<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\NotificationConfig;

beforeEach(function (): void {
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('a bool accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::activateComments())->toBeTrue(); // property default

    CurrentConfig::setActivateComments(false);
    expect(CurrentConfig::activateComments())->toBeFalse();
});

test('an int accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::webmasterId())->toBe(1);

    CurrentConfig::setWebmasterId(42);
    expect(CurrentConfig::webmasterId())->toBe(42);
});

test('a string accessor reads the property and falls back to its default', function (): void {
    expect(CurrentConfig::galleryTitle())->toBe('Piwigo');

    CurrentConfig::setGalleryTitle('Custom');
    expect(CurrentConfig::galleryTitle())->toBe('Custom');
});

test('a nullable string accessor returns null when unset', function (): void {
    expect(CurrentConfig::galleryUrl())->toBeNull();

    CurrentConfig::setGalleryUrl('https://example.test');
    expect(CurrentConfig::galleryUrl())->toBe('https://example.test');
});

test('a custom array accessor coerces and falls back to its hardcoded default', function (): void {
    expect(CurrentConfig::pictureExtensions())->toBe(['jpg', 'jpeg', 'png', 'gif', 'webp']);

    CurrentConfig::setPictureExtensions(['jpg', 'png']);
    expect(CurrentConfig::pictureExtensions())->toBe(['jpg', 'png']);
});

test('the recentPostDates custom accessor returns a NotificationConfig VO', function (): void {
    $config = CurrentConfig::recentPostDates();

    expect($config)->toBeInstanceOf(NotificationConfig::class)
        ->and($config->rss->maxDates)->toBe(5)
        ->and($config->nbm->maxCats)->toBe(9);
});

test('the userFields custom accessor returns the simplified array shape, not a VO', function (): void {
    expect(CurrentConfig::userFields())->toBe([
        'id' => 'id',
        'username' => 'username',
        'password' => 'password',
        'email' => 'mail_address',
    ]);
});

test('orderBy is a plain raw SQL-fragment string, not a structured {field,dir}[] shape', function (): void {
    expect(CurrentConfig::orderBy())->toBe('ORDER BY date_available DESC, file ASC, id ASC');

    CurrentConfig::setOrderBy('ORDER BY id ASC');
    expect(CurrentConfig::orderBy())->toBe('ORDER BY id ASC');
});

test('dumpForLog redacts sensitive properties', function (): void {
    CurrentConfig::setSmtpPassword('super-secret');
    CurrentConfig::setGalleryTitle('Visible');

    // Keyed by property name (smtpPassword/galleryTitle), not the DB param
    // name (smtp_password/gallery_title) -- CurrentConfig::all()'s own
    // docblock: reflection-based, replacing the former SCHEMA-driven all().
    $dump = CurrentConfig::dumpForLog();

    expect($dump['smtpPassword'])->toBe('********')
        ->and($dump['galleryTitle'])->toBe('Visible');
});
