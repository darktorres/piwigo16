<?php

declare(strict_types=1);

use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Filter\FilterService;
use Piwigo\Lang\Translator;
use Piwigo\Session\SessionService;

// Phase 2 global-residual sweep: FilterService::updateCatsWithFilteredData()
// now reads Piwigo\Core\FilterState instead of `global $filter;`. The old
// "categories is not an array" defensive-read test is gone with it, not
// just updated -- FilterState::categories() is typed `array` and
// FilterState::set()'s own $categories param is too, so that scenario is
// no longer constructible through the public API; the equivalent
// defensive guard (a corrupted unserialize() result) now lives once, at
// the write site in FilterService::initializeFromRequest() itself, not on
// every read.
//
// Singleton/service-locator elimination campaign, Phase 2: FilterState is
// now a container-shared instance, constructor-injected into FilterService
// -- each test constructs its own fresh instance directly, no
// reset()/Kernel::boot() needed.
//
// Phase 8: FilterService also takes a constructor-injected Lang -- this
// file never boots Kernel, so Lang::current() (no memoized pre-boot
// fallback) isn't an option; a throwaway instance is built directly
// instead, matching the same "real, cheap, DB-free collaborators" recipe
// used throughout this campaign's own test fallout fixes.
function filterServiceTestLang(): Lang
{
    return new Lang(new Translator(new \Piwigo\Config\CurrentConfig()), \Piwigo\Tests\Support\HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

test('updateCatsWithFilteredData leaves cats untouched when the filter is disabled', function (): void {
    $filterState = new FilterState();
    $filterState->set(false, '', '', [1 => ['nb_images' => 999]]);
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => 1, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData overwrites the aggregate fields for a matched category id', function (): void {
    $filterState = new FilterState();
    $filterState->set(true, '', '', [
        1 => [
            'date_last' => '2026-01-01',
            'max_date_last' => '2026-01-02',
            'count_images' => 10,
            'count_categories' => 2,
            'nb_images' => 20,
        ],
    ]);
    $cats = [0 => ['id' => 1, 'nb_images' => 5, 'untouched' => 'kept']];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0])->toBe([
        'id' => 1,
        'nb_images' => 20,
        'untouched' => 'kept',
        'date_last' => '2026-01-01',
        'max_date_last' => '2026-01-02',
        'count_images' => 10,
        'count_categories' => 2,
    ]);
});

test('updateCatsWithFilteredData skips a category id with no matching filter entry', function (): void {
    $filterState = new FilterState();
    $filterState->set(true, '', '', [2 => ['nb_images' => 20]]);
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => 1, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData skips a category row with a non-int/string id', function (): void {
    $filterState = new FilterState();
    $filterState->set(true, '', '', [1 => ['nb_images' => 20]]);
    $cats = [0 => ['id' => null, 'nb_images' => 5]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats)->toBe([0 => ['id' => null, 'nb_images' => 5]]);
});

test('updateCatsWithFilteredData matches a string category id', function (): void {
    $filterState = new FilterState();
    $filterState->set(true, '', '', ['abc' => ['nb_images' => 30]]);
    $cats = [0 => ['id' => 'abc', 'nb_images' => 5]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0]['nb_images'])->toBe(30);
});

test('updateCatsWithFilteredData continues past a non-int/string id to still process a later valid one', function (): void {
    // Kills line 263's ContinueToBreak (`break;` instead of `continue;`)
    // -- confirmed live that a `break` there wrongly aborts the whole
    // foreach after the first non-int/string id, leaving every later
    // (even valid) category row untouched.
    $filterState = new FilterState();
    $filterState->set(true, '', '', [2 => ['nb_images' => 99]]);
    $cats = [0 => ['id' => null, 'nb_images' => 5], 1 => ['id' => 2, 'nb_images' => 7]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats[1]['nb_images'])->toBe(99);
});

test('updateCatsWithFilteredData continues past a non-matching filter entry to still process a later matching one', function (): void {
    // Kills line 268's ContinueToBreak (`break;` instead of `continue;`)
    // -- confirmed live that a `break` there wrongly aborts the whole
    // foreach after the first category id with no filter entry, leaving
    // a later, genuinely matching category row untouched.
    $filterState = new FilterState();
    $filterState->set(true, '', '', [2 => ['nb_images' => 99]]);
    $cats = [0 => ['id' => 1, 'nb_images' => 5], 1 => ['id' => 2, 'nb_images' => 7]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats[1]['nb_images'])->toBe(99);
});

test('updateCatsWithFilteredData fills a missing aggregate field with null', function (): void {
    $filterState = new FilterState();
    $filterState->set(true, '', '', [1 => ['nb_images' => 20]]);
    $cats = [0 => ['id' => 1, 'nb_images' => 5]];
    $service = new FilterService($filterState, SessionService::get(), Translator::get(), filterServiceTestLang(), new \Piwigo\Config\CurrentConfig());

    $service->updateCatsWithFilteredData($cats);

    expect($cats[0]['date_last'])->toBeNull()
        ->and($cats[0]['nb_images'])->toBe(20);
});
