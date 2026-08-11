<?php

declare(strict_types=1);

use Piwigo\Picture\Projection\PictureCommentAddPageContext;

test('toArray flattens the comment_add bag', function (): void {
    $bag = [
        'F_ACTION' => '/picture.php',
        'KEY' => 'abc',
    ];

    expect((new PictureCommentAddPageContext($bag))->toArray())
        ->toBe([
            'comment_add' => $bag,
        ]);
});
