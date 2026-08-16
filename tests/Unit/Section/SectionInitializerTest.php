<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Section\SectionInitializer;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;

/**
 * Piwigo\Section\SectionInitializer -- URL token parser
 * ("category/12-name/start-24" -> a structured SectionUrlParse). No
 * dedicated Integration/Browser spec of its own.
 *
 * Covers the cheapest real path through `parse()`: an empty `$_GET`
 * (no query-string-as-path token) with every config default left in
 * place (`questionMarkInUrls` true, `RequestMountDepth` 0) skips the
 * `PATH_INFO` branch entirely, and `PageFilterHelper::scriptBasename()`
 * never resolves to `'picture'` under the real Pest CLI process (its
 * own `$_SERVER['SCRIPT_NAME']`/etc are the test runner's own paths,
 * never a `picture.php`-shaped one) -- so the whole picture-identifier
 * branch (with its real `badRequest()`/`redirectHtml()` wall) is never
 * reached either. `AccessControlTestFakeRedirectServiceNeverCalled`
 * doubles as a safety net here: it throws if this test's own
 * "redirect-free" assumption about this path is ever wrong.
 * `UrlService::parseSectionUrl()`'s own `'categor'`/`'tags'`/
 * `'favorites'`/etc token checks all fail against the resulting empty
 * token, so it returns its own untouched default (empty) `$page` array
 * with no further DB access at all.
 */
test('parse returns an empty, unrecognized-section result for an empty request', function (): void {
    unset($_GET, $_SERVER['PATH_INFO']);
    $_GET = [];

    $currentConfig = new CurrentConfig();
    $htmlRenderer = HtmlServiceTestFactory::build();
    $initializer = new SectionInitializer(
        $htmlRenderer,
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        UrlServiceTestFactory::build($htmlRenderer),
        new RequestMountDepth(),
        $currentConfig,
    );

    $result = $initializer->parse();

    expect($result->rootPath)
        ->toBe('')
        ->and($result->sectionUrl)
        ->toBe('')
        ->and($result->tokens)
        ->toBe([''])
        ->and($result->imageId)
        ->toBeNull()
        ->and($result->imageFile)
        ->toBeNull()
        ->and($result->parsed)
        ->toBe([]);
});
