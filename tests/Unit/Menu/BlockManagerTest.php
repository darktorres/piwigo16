<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Event\BlockManager\BlockManagerPrepareDisplay;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

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

test('DisplayBlock get_block returns the exact registered block it was constructed with', function (): void {
    $registered = new RegisteredBlock('tags', 'Tags', 'core');
    $display = new DisplayBlock($registered);

    expect($display->get_block())->toBe($registered);
});

test('register_block accepts the first registration and rejects a duplicate id', function (): void {
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $block = new RegisteredBlock('cat', 'Categories', 'core');

    expect($manager->register_block($block))->toBeTrue();
    expect($manager->register_block(new RegisteredBlock('cat', 'Duplicate', 'plugin')))->toBeFalse();
    expect($manager->get_registered_blocks())->toBe(['cat' => $block]);
});

test('prepare_display assigns positions in registration order (idx*50) with no config override', function (): void {
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
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

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
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

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->register_block(new RegisteredBlock('tags', 'Tags', 'core'));
    $manager->prepare_display();

    expect($manager->is_hidden('cat'))->toBeTrue();
    expect($manager->is_hidden('tags'))->toBeTrue();
    expect($manager->get_block('cat'))->toBeNull();
});

test('prepare_display sorts display blocks by resolved position, independent of registration order', function (): void {
    CurrentConfig::setBlkMenubar(['second' => 10, 'first' => 20]);

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
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

test('prepare_display falls back to idx*50 positioning when a block\'s config value is non-numeric', function (): void {
    // 'first' has an explicit but non-numeric config entry, forcing
    // is_numeric($raw_pos) to false and exercising the ternary's *own*
    // "$idx * 50" fallback (distinct from the one on the line above that
    // only fires when the key is entirely absent from $mb_conf).
    CurrentConfig::setBlkMenubar(['first' => 'not-a-number']);

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('first', 'First', 'core'));
    $manager->register_block(new RegisteredBlock('second', 'Second', 'core'));

    $manager->prepare_display();

    $first = $manager->get_block('first');
    $second = $manager->get_block('second');
    if ($first === null || $second === null) {
        throw new RuntimeException('Expected both blocks to be visible after prepare_display()');
    }
    expect($first->get_position())->toBe(50);
    expect($second->get_position())->toBe(100);
});

test('prepare_display casts a numeric-string config position to a real int', function (): void {
    // A numeric string survives is_numeric() but must go through the
    // (int) cast before being stored -- otherwise DisplayBlock::set_position()
    // (untyped param) would happily store the string "5" instead of the
    // int 5, which the strict toBe(5) below would catch.
    CurrentConfig::setBlkMenubar(['cat' => '5']);

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    $cat = $manager->get_block('cat');
    if ($cat === null) {
        throw new RuntimeException('Expected the cat block to remain visible after prepare_display()');
    }
    expect($cat->get_position())->toBe(5);
});

test('prepare_display treats a resolved position of exactly 1 as visible', function (): void {
    // Pins down the ">" boundary itself (as opposed to the existing
    // 0/negative test, which can't distinguish "> 0" from "> 1" since both
    // reject 0 identically) -- 1 is the smallest position that must remain
    // visible under "> 0" while a "> 1" mutant would wrongly hide it.
    CurrentConfig::setBlkMenubar(['cat' => 1]);

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    expect($manager->is_hidden('cat'))->toBeFalse();
    $cat = $manager->get_block('cat');
    if ($cat === null) {
        throw new RuntimeException('Expected the cat block to be visible after prepare_display()');
    }
    expect($cat->get_position())->toBe(1);
});

test('prepare_display sorts display blocks before firing blockmanager_prepare_display, so handlers observe already-sorted order', function (): void {
    CurrentConfig::setBlkMenubar(['second' => 10, 'first' => 20]);

    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('first', 'First', 'core'));
    $manager->register_block(new RegisteredBlock('second', 'Second', 'core'));

    $observedIds = null;
    $handler = function (BlockManagerPrepareDisplay $event) use (&$observedIds): void {
        $target = $event->value;
        if (! $target instanceof BlockManager) {
            throw new RuntimeException('blockmanager_prepare_display: expected a BlockManager instance');
        }

        $reflection = new ReflectionProperty(BlockManager::class, 'display_blocks');
        $displayBlocks = $reflection->getValue($target);
        if (! is_iterable($displayBlocks)) {
            throw new RuntimeException('Expected display_blocks to be iterable');
        }

        $ids = [];
        foreach ($displayBlocks as $id => $block) {
            $ids[] = $id;
        }
        $observedIds = $ids;
    };
    EventDispatcher::get()->addTypedHandler(BlockManagerPrepareDisplay::class, $handler);

    try {
        $manager->prepare_display();
    } finally {
        EventDispatcher::get()->removeEventHandler(BlockManagerPrepareDisplay::class, $handler);
    }

    // Also proves the event actually fires with $this as the payload
    // (an empty-array payload, or a call that never fires at all, would
    // leave $observedIds null instead).
    expect($observedIds)->toBe(['second', 'first']);
});

test('prepare_display re-sorts after blockmanager_prepare_display handlers change block positions', function (): void {
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('first', 'First', 'core'));
    $manager->register_block(new RegisteredBlock('second', 'Second', 'core'));

    // Default idx*50 positions put 'first' (50) before 'second' (100), so
    // the pre-event sort_blocks() call leaves the array in that same
    // order -- the handler then flips the relative order via the public
    // set_block_position() API, and only a second, post-event sort_blocks()
    // call can put 'second' back in front.
    $handler = function (BlockManagerPrepareDisplay $event): void {
        $target = $event->value;
        if (! $target instanceof BlockManager) {
            throw new RuntimeException('blockmanager_prepare_display: expected a BlockManager instance');
        }

        $target->set_block_position('first', 999);
        $target->set_block_position('second', 1);
    };
    EventDispatcher::get()->addTypedHandler(BlockManagerPrepareDisplay::class, $handler);

    try {
        $manager->prepare_display();
    } finally {
        EventDispatcher::get()->removeEventHandler(BlockManagerPrepareDisplay::class, $handler);
    }

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
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
    $manager->register_block(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepare_display();

    expect($manager->is_hidden('cat'))->toBeFalse();
    $manager->hide_block('cat');
    expect($manager->is_hidden('cat'))->toBeTrue();
});

test('set_block_position updates the position of a visible block, and is a no-op for an unknown/hidden one', function (): void {
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());
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
    $manager = new BlockManager('menubar', EventDispatcher::get(), CurrentTemplate::current());

    expect($manager->get_id())->toBe('menubar');
});
