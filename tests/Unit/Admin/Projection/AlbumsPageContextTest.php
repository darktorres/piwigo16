<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\AlbumsPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new AlbumsPageContext(
        nbCats: 12,
        openCat: '-1',
        fAction: '/admin.php?page=albums',
        delayBeforeAutoOpen: 500,
        posPref: 'first',
        albumData: [['id' => '1', 'name' => 'Holidays']],
        pwgToken: 'abc123',
        nbAlbums: 12,
        adminPageTitle: 'Albums',
        lightAlbumManager: 0,
    );

    expect($context->toArray())->toBe([
        'nb_cats' => 12,
        'open_cat' => '-1',
        'F_ACTION' => '/admin.php?page=albums',
        'delay_before_autoOpen' => 500,
        'POS_PREF' => 'first',
        'album_data' => [['id' => '1', 'name' => 'Holidays']],
        'PWG_TOKEN' => 'abc123',
        'nb_albums' => 12,
        'ADMIN_PAGE_TITLE' => 'Albums',
        'light_album_manager' => 0,
    ]);
});
