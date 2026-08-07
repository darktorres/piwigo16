<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Lang\LanguageEntity;

test('constructs with distinct values for every property', function (): void {
    $language = new LanguageEntity(id: LangCode::from('fr_FR'), version: '2.1.0', name: 'Français');

    expect($language->id)->toEqual(LangCode::from('fr_FR'))
        ->and($language->version)->toBe('2.1.0')
        ->and($language->name)->toBe('Français');
});

test('constructs with name null explicitly', function (): void {
    $language = new LanguageEntity(id: LangCode::from('it_IT'), version: '1.5.2', name: null);

    expect($language->name)->toBeNull()
        ->and($language->id)->toEqual(LangCode::from('it_IT'))
        ->and($language->version)->toBe('1.5.2');
});

test('defaults version to \'0\' when omitted', function (): void {
    $language = new LanguageEntity(id: LangCode::from('en_GB'), name: 'English (UK)');

    expect($language->version)->toBe('0')
        ->and($language->id)->toEqual(LangCode::from('en_GB'))
        ->and($language->name)->toBe('English (UK)');
});

test('defaults name to null when omitted', function (): void {
    $language = new LanguageEntity(id: LangCode::from('de_DE'), version: '3.0.1');

    expect($language->name)->toBeNull()
        ->and($language->id)->toEqual(LangCode::from('de_DE'))
        ->and($language->version)->toBe('3.0.1');
});

test('defaults both version and name when only id is given', function (): void {
    $language = new LanguageEntity(id: LangCode::from('es_ES'));

    expect($language->id)->toEqual(LangCode::from('es_ES'))
        ->and($language->version)->toBe('0')
        ->and($language->name)->toBeNull();
});
