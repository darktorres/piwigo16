<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\RegisteredBlock;

/**
 * Piwigo\Menu\BlockManager/DisplayBlock/RegisteredBlock -- had zero
 * dedicated coverage (see /home/torres/.claude/plans/piped-enchanting-
 * spark.md, Wave 1) despite being reachable from MenubarRenderer::render()
 * on nearly every gallery page load; that indirect coverage never reaches
 * this class's own branch logic (register_block's duplicate-id guard,
 * prepare_display's position resolution/hiding, sort order) directly.
 * BlockManager::apply() (needs a real compiled .tpl handle) is
 * deliberately excluded -- covered incidentally through MenubarRenderer's
 * own real Browser-suite page loads instead.
 */
afterEach(function (): void {
    CurrentConfig::setBlkMenubar(null);
});

test('RegisteredBlock exposes its id/name/owner', function (): void {
    $block = new RegisteredBlock('cat', 'Categories', 'core');

    expect($block->get_id())->toBe('cat');
    expect($block->get_name())->toBe('Categories');
    expect($block->get_owner())->toBe('core');
});

test('DisplayBlock falls back to the registered block\'s name until a title is explicitly set', function (): void {
    $registered = new RegisteredBlock('cat', 'Categories', 'core');
    $display = new DisplayBlock($registered);

    expect($display->get_title())->toBe('Categories');

    $display->set_title('Custom Title');
    expect($display->get_title())->toBe('Custom Title');
});

test('DisplayBlock get_position/set_position round-trip', function (): void {
    $display = new DisplayBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $display->set_position(42);

    expect($display->get_position())->toBe(42);
});

test('register_block accepts the first registration and rejects a duplicate id', function (): void {
    $manager = new BlockManager('menubar');
    $block = new RegisteredBlock('cat', 'Categories', 'core');

    expect($manager->register_block($block))->toBeTrue();
    expect($manager->register_block(new RegisteredBlock('cat', 'Duplicate', 'plugin')))->toBeFalse();
    expect($manager->get_registered_blocks())->toBe(['cat' => $block]);
});

test('prepare_display assigns positions in registration order (idx*50) with no config override', function (): void {
    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('first', 'First', 'core'));
    $manager->register_block(new RegisteredBlock('second', 'Second', 'core'));

    $manager->prepare_display();

    expect($manager->is_hidden('first'))->toBeFalse();
    $first = $manager->get_block('first');
    $second = $manager->get_block('second');
    if ($first === null || $second === null) {
        throw new RuntimeException('Expected both blocks to be visible after prepare_display()');
    }
    expect($first->get_position())->toBe(50);
    expect($second->get_position())->toBe(100);
});

test('prepare_display honors an explicit position from blk_menubar config', function (): void {
    CurrentConfig::setBlkMenubar(['cat' => 5]);

    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    $cat = $manager->get_block('cat');
    if ($cat === null) {
        throw new RuntimeException('Expected the cat block to be visible after prepare_display()');
    }
    expect($cat->get_position())->toBe(5);
});

test('prepare_display hides a block whose configured position is 0 or negative', function (): void {
    CurrentConfig::setBlkMenubar(['cat' => 0, 'tags' => -10]);

    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->register_block(new RegisteredBlock('tags', 'Tags', 'core'));
    $manager->prepare_display();

    expect($manager->is_hidden('cat'))->toBeTrue();
    expect($manager->is_hidden('tags'))->toBeTrue();
    expect($manager->get_block('cat'))->toBeNull();
});

test('prepare_display sorts display blocks by resolved position, independent of registration order', function (): void {
    CurrentConfig::setBlkMenubar(['second' => 10, 'first' => 20]);

    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('first', 'First', 'core'));
    $manager->register_block(new RegisteredBlock('second', 'Second', 'core'));
    $manager->prepare_display();

    $ids = [];
    $reflection = new ReflectionProperty(BlockManager::class, 'display_blocks');
    $displayBlocks = $reflection->getValue($manager);
    if (! is_iterable($displayBlocks)) {
        throw new RuntimeException('Expected display_blocks to be iterable');
    }
    foreach ($displayBlocks as $id => $block) {
        $ids[] = $id;
    }

    expect($ids)->toBe(['second', 'first']);
});

test('hide_block removes a previously visible block', function (): void {
    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    expect($manager->is_hidden('cat'))->toBeFalse();
    $manager->hide_block('cat');
    expect($manager->is_hidden('cat'))->toBeTrue();
});

test('set_block_position updates the position of a visible block, and is a no-op for an unknown/hidden one', function (): void {
    $manager = new BlockManager('menubar');
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    $manager->set_block_position('cat', 999);
    $cat = $manager->get_block('cat');
    if ($cat === null) {
        throw new RuntimeException('Expected the cat block to remain visible after set_block_position()');
    }
    expect($cat->get_position())->toBe(999);

    // no exception, no-op for a block that was never registered
    $manager->set_block_position('does-not-exist', 1);
});

test('get_id returns the manager\'s own id', function (): void {
    $manager = new BlockManager('menubar');

    expect($manager->get_id())->toBe('menubar');
});
