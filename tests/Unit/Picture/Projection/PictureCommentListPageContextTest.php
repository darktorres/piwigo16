<?php

declare(strict_types=1);

use Piwigo\Core\Projection\Navbar;
use Piwigo\Picture\Projection\PictureCommentListPageContext;

test('toArray includes an empty comments list (not omitted)', function (): void {
    $context = new PictureCommentListPageContext(commentCount: 0, navbar: Navbar::none(), comments: []);

    expect($context->toArray())
        ->toBe([
            'COMMENT_COUNT' => 0,
            'navbar' => [],
            'comments' => [],
        ]);
});

test('toArray includes the real comments list when set', function (): void {
    $context = new PictureCommentListPageContext(commentCount: 5, navbar: new Navbar(nbPage: 2), comments: [[
        'ID' => 1,
        'AUTHOR' => 'jane',
    ]]);

    expect($context->toArray())
        ->toBe([
            'COMMENT_COUNT' => 5,
            'navbar' => [
                'NB_PAGE' => 2,
            ],
            'comments' => [[
                'ID' => 1,
                'AUTHOR' => 'jane',
            ]],
        ]);
});
