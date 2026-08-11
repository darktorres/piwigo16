<?php

declare(strict_types=1);

use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\Projection\MenubarBlocksPageContext;
use Piwigo\Menu\RegisteredBlock;

test('toArray flattens the blocks list', function (): void {
    $block = new DisplayBlock(new RegisteredBlock('some-block', 'Some Block', 'some-plugin'));

    expect((new MenubarBlocksPageContext([
        'some-block' => $block,
    ]))->toArray())
        ->toBe([
            'blocks' => [
                'some-block' => $block,
            ],
        ]);
});
