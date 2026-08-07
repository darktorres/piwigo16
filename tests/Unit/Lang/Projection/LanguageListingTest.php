<?php

declare(strict_types=1);

use Piwigo\Lang\Projection\LanguageListing;

test('constructs with the given id and name', function (): void {
    $language = new LanguageListing('en_UK', 'English (Great Britain)');

    expect($language->id)->toBe('en_UK')
        ->and($language->name)->toBe('English (Great Britain)');
});

test('toArray round-trips the id and name', function (): void {
    $language = new LanguageListing('fr_FR', 'Français');

    expect($language->toArray())->toBe([
        'id' => 'fr_FR',
        'name' => 'Français',
    ]);
});
