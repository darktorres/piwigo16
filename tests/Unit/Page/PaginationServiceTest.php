<?php

declare(strict_types=1);

use Piwigo\Core\PaginationService;

beforeEach(function (): void {
    $GLOBALS['conf'] = ['paginate_pages_around' => 2];
});

test('createNavigationBar returns an empty bar when everything fits on one page', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 10, 0, 20);

    expect($navbar)->toBe([]);
});

test('createNavigationBar computes the current page and total page count', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar['CURRENT_PAGE'])->toBe(3);
    expect($navbar['NB_PAGE'])->toBe(5);
});

test('createNavigationBar omits URL_FIRST/URL_PREV on the first page', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, 0, 20);

    expect($navbar)->not->toHaveKey('URL_FIRST');
    expect($navbar)->not->toHaveKey('URL_PREV');
    expect($navbar)->toHaveKey('URL_NEXT');
    expect($navbar)->toHaveKey('URL_LAST');
});

test('createNavigationBar omits URL_NEXT/URL_LAST on the last page', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, 80, 20);

    expect($navbar)->toHaveKey('URL_FIRST');
    expect($navbar)->toHaveKey('URL_PREV');
    expect($navbar)->not->toHaveKey('URL_NEXT');
    expect($navbar)->not->toHaveKey('URL_LAST');
});

test('createNavigationBar clamps a negative start to zero', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, -5, 20);

    expect($navbar['CURRENT_PAGE'])->toBe(1);
});

test('createNavigationBar accepts numeric strings for nbElement and start', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', '100', '40', 20);

    expect($navbar['CURRENT_PAGE'])->toBe(3);
});

test('createNavigationBar builds clean-url-style page links when requested', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php/category/1', 100, 40, 20, true);

    expect($navbar['URL_NEXT'])->toBe('index.php/category/1/start-60');
});

test('createNavigationBar builds query-string-style page links by default', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20);

    expect($navbar['URL_NEXT'])->toBe('index.php?start=60');
});

test('createNavigationBar respects a custom param name', function (): void {
    $service = new PaginationService();

    $navbar = $service->createNavigationBar('index.php', 100, 40, 20, false, 'offset');

    expect($navbar['URL_NEXT'])->toBe('index.php?offset=60');
});
