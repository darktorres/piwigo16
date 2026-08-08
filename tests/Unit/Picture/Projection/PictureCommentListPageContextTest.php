<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\PictureCommentListPageContext;

test('toArray always seeds comments as empty', function (): void {
    $context = new PictureCommentListPageContext(commentCount: 5, navbar: ['NB_PAGE' => 2]);

    expect($context->toArray())->toBe([
        'COMMENT_COUNT' => 5,
        'navbar' => ['NB_PAGE' => 2],
        'comments' => [],
    ]);
});
