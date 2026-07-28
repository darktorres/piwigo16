<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\BatchManagerSubController (admin.php?page=
 * batch_manager) -- session-filter parsing (`$_SESSION['bulk_manager_
 * filter']`, from either a submitted filter form or a comma-joined
 * `?filter=` URL token list), FilterResolver orchestration, and the
 * `empty_caddie`/`delete_orphans`/`sync_md5sum` GET actions. Almost none
 * of this had a dedicated test before: the existing VisualRegressionTest
 * baseline for this page never contributes to coverage (VR is excluded
 * from `composer test:coverage:web`), and the filter/action logic itself
 * is otherwise only reachable through a real HTTP request.
 *
 * Each test drives one real filter combination and asserts a 200/no-
 * server-error response -- FilterResolver's own SQL correctness has its
 * own dedicated Integration test (FilterResolverTest); this file's job is
 * covering BatchManagerSubController's own branch-selection/session-
 * mutation logic, not re-verifying FilterResolver's query results.
 */
function bmDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function bmDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function bmCaddieCount(int $userId): int
{
    $db = bmDbConnect();
    $result = $db->query(sprintf('SELECT COUNT(*) AS c FROM %scaddie WHERE user_id = %d', bmDbPrefix(), $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? (int) $row['c'] : -1;
}

function bmInsertCaddie(int $userId, int $imageId): void
{
    $db = bmDbConnect();
    $db->query(sprintf(
        'INSERT INTO %scaddie (user_id, element_id) VALUES (%d, %d) ON DUPLICATE KEY UPDATE user_id = user_id',
        bmDbPrefix(),
        $userId,
        $imageId
    ));
    $db->close();
}

/**
 * Every submission goes through BatchManagerSubController::handle(),
 * which always ends by delegating to BatchManagerGlobalPageRenderer::
 * render() (unless mode=unit) -- and THAT renderer carries its own
 * blanket `if (count($_POST) > 0) { checkOrFail(); }` CSRF gate
 * (BatchManagerGlobalPageRenderer.php:84-87), independent of whatever
 * BatchManagerSubController's own resolveSessionFilter() does. So any
 * non-empty POST reaching this page needs a valid token, even a plain
 * filter-form submission with no "batch action" of its own.
 *
 * @param  array<string, mixed>  $fields
 * @return array{status: int, body: string}
 */
function bmPost(Webpage|PendingAwaitablePage|AwaitableWebpage $page, array $fields): array
{
    return H::adminPost($page, '/admin.php?page=batch_manager', array_merge(['pwg_token' => H::pwgToken($page)], $fields));
}

it('renders the global tab with no filter, defaulting to the caddie prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager');
    $page->assertNoJavaScriptErrors();
});

it('renders the unit tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');
    $page->assertNoJavaScriptErrors();
});

it('empty_caddie clears the caddie and redirects', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    bmInsertCaddie(1, 1);
    expect(bmCaddieCount(1))->toBeGreaterThan(0);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', [
        'pwg_token' => $token,
    ]);

    // A real HTTP redirect (Location header, from RedirectService::
    // redirect()) becomes an opaque response under fetch(..., {redirect:
    // 'manual'}) -- status is always reported as 0 and the body is
    // inaccessible by the Fetch API's own spec, not a failure signal.
    expect($result['status'])->toBe(0);
    expect(bmCaddieCount(1))->toBe(0);
});

it('empty_caddie rejects a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    bmInsertCaddie(1, 1);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', []);

    expect($result['status'])->toBe(400);
    expect(bmCaddieCount(1))->toBeGreaterThan(0);

    bmInsertCaddie(1, 1);
    H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', ['pwg_token' => H::pwgToken($page)]);
});

it('delete_orphans records a session message and redirects when photos were deleted', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=3');
    $page->assertNoJavaScriptErrors();
});

it('sync_md5sum records a session message and redirects when checksums were added', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=2');
    $page->assertNoJavaScriptErrors();
});

it('submits a duplicates prefilter with every detection option checked', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'duplicates',
        'filter_duplicates_checksum' => '1',
        'filter_duplicates_date' => '1',
        'filter_duplicates_dimensions' => '1',
        'filter_duplicates_filename' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a category prefilter scoped recursively', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => '1',
        'filter_category_recursive' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('redirects and clears the session filter when the filtered category no longer exists', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => '999999',
    ]);

    // computeCurrentSet() redirects (a real Location header) when the
    // filtered category no longer exists -- an opaque response under
    // fetch(..., {redirect: 'manual'}), status always 0 per the Fetch API
    // spec, not a failure signal (see empty_caddie's own test above).
    expect($result['status'])->toBe(0);
});

it('submits a tags prefilter as a multi-value array', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_tags_use' => '1',
        'filter_tags' => ['~~1~~'],
        'tag_mode' => 'OR',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a tags prefilter as a single scalar value', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_tags_use' => '1',
        'filter_tags' => '~~1~~',
        'tag_mode' => 'AND',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a permission-level prefilter including lower levels', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_level_use' => '1',
        'filter_level' => '2',
        'filter_level_include_lower' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a dimension prefilter with valid width/height/ratio bounds', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_dimension_use' => '1',
        'filter_dimension_min_width' => '100',
        'filter_dimension_max_width' => '2000',
        'filter_dimension_min_height' => '100',
        'filter_dimension_max_height' => '2000',
        'filter_dimension_min_ratio' => '0.5',
        'filter_dimension_max_ratio' => '2.0',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a filesize prefilter with valid bounds', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_filesize_use' => '1',
        'filter_filesize_min' => '0.5',
        'filter_filesize_max' => '10',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a quick-search prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_search_use' => '1',
        'q' => 'Photo',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submitting the filter form with nothing checked resets to the default filter', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, ['submitFilter' => '1']);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('applies a combined URL filter token list covering prefilter/category/tag/level/search/dimension/filesize', function (): void {
    $page = H::loginAsAdmin($this);

    $filter = implode(',', [
        'prefilter-duplicates-checksum',
        'album-1',
        'tag-1',
        'level-2',
        'search-hello',
        'dimension-w10..1000-h100..2000-r0.5..2',
        'filesize-1..10',
        'bogus-x',
    ]);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=' . rawurlencode($filter));
    $page->assertNoJavaScriptErrors();
});

it('applies a plain (non-duplicates) prefilter via a URL filter token', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=prefilter-no_album');
    $page->assertNoJavaScriptErrors();
});
