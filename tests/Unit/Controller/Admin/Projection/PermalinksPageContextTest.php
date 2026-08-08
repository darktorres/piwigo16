<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\PermalinksPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new PermalinksPageContext(
        nbCats: 5,
        sortId: '<a href="?psf=id">↓</a>',
        sortName: '<em>↓</em>',
        sortPermalink: '<a href="?psf=permalink">↓</a>',
        permalinks: [['id' => 1, 'name' => 'Album']],
        sortOldCatId: '<a href="?dpsf=cat_id">↓</a>',
        sortOldPermalink: '<a href="?dpsf=permalink">↓</a>',
        sortOldDateDeleted: '<a href="?dpsf=date_deleted">↓</a>',
        sortOldLastHit: '<a href="?dpsf=last_hit">↓</a>',
        sortOldHit: '<a href="?dpsf=hit">↓</a>',
        pwgToken: 'abc123',
        helpUrl: '/admin/popuphelp.php?page=permalinks',
        deletedPermalinks: [['cat_id' => 2, 'permalink' => 'old']],
        adminPageTitle: 'Albums',
    );

    expect($context->toArray())->toBe([
        'nb_cats' => 5,
        'SORT_ID' => '<a href="?psf=id">↓</a>',
        'SORT_NAME' => '<em>↓</em>',
        'SORT_PERMALINK' => '<a href="?psf=permalink">↓</a>',
        'permalinks' => [['id' => 1, 'name' => 'Album']],
        'SORT_OLD_CAT_ID' => '<a href="?dpsf=cat_id">↓</a>',
        'SORT_OLD_PERMALINK' => '<a href="?dpsf=permalink">↓</a>',
        'SORT_OLD_DATE_DELETED' => '<a href="?dpsf=date_deleted">↓</a>',
        'SORT_OLD_LAST_HIT' => '<a href="?dpsf=last_hit">↓</a>',
        'SORT_OLD_HIT' => '<a href="?dpsf=hit">↓</a>',
        'PWG_TOKEN' => 'abc123',
        'U_HELP' => '/admin/popuphelp.php?page=permalinks',
        'deleted_permalinks' => [['cat_id' => 2, 'permalink' => 'old']],
        'ADMIN_PAGE_TITLE' => 'Albums',
    ]);
});
