<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Lang\Translator;
use Piwigo\Page\PageDataPayload;
use Piwigo\Tests\Support\HtmlServiceTestFactory;

/**
 * A real Lang instance (Translator/HtmlRenderingInterface/Paths/
 * InstallationFlag all real, cheap, DB-free collaborators -- same
 * construction shape LangTest.php's own langTestMake() uses), so `t()`
 * resolution below exercises the exact same code path a `{_ '...'}`
 * template tag goes through, not a stand-in.
 */
function pageDataPayloadTestMakeLang(): Lang
{
    return new Lang(
        new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        HtmlServiceTestFactory::build(),
        Paths::fromRoot(sys_get_temp_dir()),
        new InstallationFlag(),
    );
}

test('toArray merges bodyData and exposedData, with exposedData winning on a key collision', function (): void {
    $state = new PageState();
    $state->setBodyData('combined_category_ids', [1, 2]);
    $state->setBodyData('search_id', 'legacy-value');
    $state->exposeData('search_id', 'declared-value');
    $state->exposeData('csrf_token', 'abc123');

    $payload = new PageDataPayload($state, pageDataPayloadTestMakeLang());

    expect($payload->toArray())
        ->toBe([
            'data' => [
                'combined_category_ids' => [1, 2],
                'search_id' => 'declared-value',
                'csrf_token' => 'abc123',
            ],
            'strings' => [],
        ]);
});

test('toArray resolves every exposed string key through Lang::t', function (): void {
    $state = new PageState();
    $lang = pageDataPayloadTestMakeLang();
    $lang->loadArray([
        'Loading' => 'Chargement',
    ]);
    $state->exposeString('Loading');
    $state->exposeString('Unknown Key');

    $payload = new PageDataPayload($state, $lang);

    expect($payload->toArray()['strings'])
        ->toBe([
            'Loading' => 'Chargement',
            // Lang::t()'s own documented behavior for a key with no
            // loaded translation: return the key itself, not throw or
            // silently drop it.
            'Unknown Key' => 'Unknown Key',
        ]);
});

test('exposing the same string key twice still resolves to a single strings entry', function (): void {
    $state = new PageState();
    $lang = pageDataPayloadTestMakeLang();
    $lang->loadArray([
        'Loading' => 'Chargement',
    ]);
    $state->exposeString('Loading');
    $state->exposeString('Loading');

    $payload = new PageDataPayload($state, $lang);

    expect($payload->toArray()['strings'])
        ->toBe([
            'Loading' => 'Chargement',
        ]);
});

test('toJson round-trips a non-ASCII translation as literal UTF-8, not escaped', function (): void {
    $state = new PageState();
    $lang = pageDataPayloadTestMakeLang();
    $lang->loadArray([
        'greeting' => 'Bonjour 中文',
    ]);
    $state->exposeString('greeting');

    $payload = new PageDataPayload($state, $lang);
    $json = $payload->toJson();

    expect($json)
        ->toContain('中文')
        ->and(json_decode($json, true))
        ->toBe([
            'data' => [],
            'strings' => [
                'greeting' => 'Bonjour 中文',
            ],
        ]);
});

test('toJson neutralizes a </script>-breaking value rather than embedding it literally', function (): void {
    $state = new PageState();
    $state->exposeData('dangerous', 'Hi </script><!--<script>alert(1)</script>--> & more');

    $payload = new PageDataPayload($state, pageDataPayloadTestMakeLang());
    $json = $payload->toJson();

    expect($json)
        ->not->toContain('</script>')
        ->not->toContain('<!--')
        ->and(json_decode($json, true))
        ->toBe([
            'data' => [
                'dangerous' => 'Hi </script><!--<script>alert(1)</script>--> & more',
            ],
            'strings' => [],
        ]);
});
