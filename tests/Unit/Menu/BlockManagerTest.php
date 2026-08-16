<?php

declare(strict_types=1);

use Piwigo\Menu\BlockManager;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\Event\BlockManagerPrepareDisplay;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;

/**
 * Piwigo\Menu\BlockManager/DisplayBlock/RegisteredBlock are reachable
 * from MenubarRenderer::render() on nearly every gallery page load, but
 * that indirect coverage never reaches this class's own branch logic
 * (registerBlock's duplicate-id guard, prepareDisplay's position
 * resolution/hiding, sort order) directly -- covered here instead.
 * BlockManager::apply() (needs a real compiled .tpl handle) is
 * deliberately excluded -- covered incidentally through MenubarRenderer's
 * own real Browser-suite page loads instead.
 */
afterEach(function (): void {
    CurrentConfigTestFactory::get()->blkMenubar = null;
});

test('RegisteredBlock exposes its id/name/owner', function (): void {
    $block = new RegisteredBlock('cat', 'Categories', 'core');

    expect($block->getId())
        ->toBe('cat');
    expect($block->getName())
        ->toBe('Categories');
    expect($block->getOwner())
        ->toBe('core');
});

test('DisplayBlock falls back to the registered block\'s name until a title is explicitly set', function (): void {
    $registered = new RegisteredBlock('cat', 'Categories', 'core');
    $display = new DisplayBlock($registered);

    expect($display->getTitle())
        ->toBe('Categories');

    $display->setTitle('Custom Title');
    expect($display->getTitle())
        ->toBe('Custom Title');
});

test('DisplayBlock getPosition/setPosition round-trip', function (): void {
    $display = new DisplayBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $display->setPosition(42);

    expect($display->getPosition())
        ->toBe(42);
});

test('DisplayBlock getBlock returns the exact registered block it was constructed with', function (): void {
    $registered = new RegisteredBlock('tags', 'Tags', 'core');
    $display = new DisplayBlock($registered);

    expect($display->getBlock())
        ->toBe($registered);
});

test('registerBlock accepts the first registration and rejects a duplicate id', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());
    $block = new RegisteredBlock('cat', 'Categories', 'core');

    expect($manager->registerBlock($block))
        ->toBeTrue();
    expect($manager->registerBlock(new RegisteredBlock('cat', 'Duplicate', 'plugin')))
        ->toBeFalse();
    expect($manager->getRegisteredBlocks())
        ->toBe([
            'cat' => $block,
        ]);
});

test('prepareDisplay assigns positions in registration order (idx*50) with no config override', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());
    $manager->registerBlock(new RegisteredBlock('first', 'First', 'core'));
    $manager->registerBlock(new RegisteredBlock('second', 'Second', 'core'));

    $manager->prepareDisplay();

    expect($manager->isHidden('first'))
        ->toBeFalse();
    $first = $manager->getBlock('first');
    $second = $manager->getBlock('second');
    if (! $first instanceof DisplayBlock || ! $second instanceof DisplayBlock) {
        throw new RuntimeException('Expected both blocks to be visible after prepareDisplay()');
    }
    expect($first->getPosition())
        ->toBe(50);
    expect($second->getPosition())
        ->toBe(100);
});

test('prepareDisplay honors an explicit position from blk_menubar config', function (): void {
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'cat' => 5,
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepareDisplay();

    $cat = $manager->getBlock('cat');
    if (! $cat instanceof DisplayBlock) {
        throw new RuntimeException('Expected the cat block to be visible after prepareDisplay()');
    }
    expect($cat->getPosition())
        ->toBe(5);
});

test('prepareDisplay hides a block whose configured position is 0 or negative', function (): void {
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'cat' => 0,
        'tags' => -10,
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->registerBlock(new RegisteredBlock('tags', 'Tags', 'core'));
    $manager->prepareDisplay();

    expect($manager->isHidden('cat'))
        ->toBeTrue();
    expect($manager->isHidden('tags'))
        ->toBeTrue();
    expect($manager->getBlock('cat'))
        ->toBeNull();
});

test('prepareDisplay sorts display blocks by resolved position, independent of registration order', function (): void {
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'second' => 10,
        'first' => 20,
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('first', 'First', 'core'));
    $manager->registerBlock(new RegisteredBlock('second', 'Second', 'core'));
    $manager->prepareDisplay();

    $ids = [];
    $reflection = new ReflectionProperty(BlockManager::class, 'display_blocks');
    $displayBlocks = $reflection->getValue($manager);
    if (! is_iterable($displayBlocks)) {
        throw new RuntimeException('Expected display_blocks to be iterable');
    }
    foreach ($displayBlocks as $id => $block) {
        $ids[] = $id;
    }

    expect($ids)
        ->toBe(['second', 'first']);
});

test('prepareDisplay falls back to idx*50 positioning when a block\'s config value is non-numeric', function (): void {
    // 'first' has an explicit but non-numeric config entry, forcing
    // is_numeric($raw_pos) to false and exercising the ternary's *own*
    // "$idx * 50" fallback (distinct from the one on the line above that
    // only fires when the key is entirely absent from $mb_conf).
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'first' => 'not-a-number',
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('first', 'First', 'core'));
    $manager->registerBlock(new RegisteredBlock('second', 'Second', 'core'));

    $manager->prepareDisplay();

    $first = $manager->getBlock('first');
    $second = $manager->getBlock('second');
    if (! $first instanceof DisplayBlock || ! $second instanceof DisplayBlock) {
        throw new RuntimeException('Expected both blocks to be visible after prepareDisplay()');
    }
    expect($first->getPosition())
        ->toBe(50);
    expect($second->getPosition())
        ->toBe(100);
});

test('prepareDisplay casts a numeric-string config position to a real int', function (): void {
    // A numeric string survives is_numeric() but must go through the
    // (int) cast before being stored -- otherwise DisplayBlock::setPosition()
    // (untyped param) would happily store the string "5" instead of the
    // int 5, which the strict toBe(5) below would catch.
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'cat' => '5',
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepareDisplay();

    $cat = $manager->getBlock('cat');
    if (! $cat instanceof DisplayBlock) {
        throw new RuntimeException('Expected the cat block to remain visible after prepareDisplay()');
    }
    expect($cat->getPosition())
        ->toBe(5);
});

test('prepareDisplay treats a resolved position of exactly 1 as visible', function (): void {
    // Pins down the ">" boundary itself (as opposed to the existing
    // 0/negative test, which can't distinguish "> 0" from "> 1" since both
    // reject 0 identically) -- 1 is the smallest position that must remain
    // visible under "> 0" while a "> 1" mutant would wrongly hide it.
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'cat' => 1,
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepareDisplay();

    expect($manager->isHidden('cat'))
        ->toBeFalse();
    $cat = $manager->getBlock('cat');
    if (! $cat instanceof DisplayBlock) {
        throw new RuntimeException('Expected the cat block to be visible after prepareDisplay()');
    }
    expect($cat->getPosition())
        ->toBe(1);
});

test('prepareDisplay sorts display blocks before firing blockmanager_prepare_display, so handlers observe already-sorted order', function (): void {
    $currentConfig = CurrentConfigTestFactory::get();
    $currentConfig->blkMenubar = [
        'second' => 10,
        'first' => 20,
    ];

    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), $currentConfig);
    $manager->registerBlock(new RegisteredBlock('first', 'First', 'core'));
    $manager->registerBlock(new RegisteredBlock('second', 'Second', 'core'));

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
    EventDispatcherTestFactory::get()->addTypedHandler(BlockManagerPrepareDisplay::class, $handler);

    try {
        $manager->prepareDisplay();
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(BlockManagerPrepareDisplay::class, $handler);
    }

    // Also proves the event actually fires with $this as the payload
    // (an empty-array payload, or a call that never fires at all, would
    // leave $observedIds null instead).
    expect($observedIds)
        ->toBe(['second', 'first']);
});

test('prepareDisplay re-sorts after blockmanager_prepare_display handlers change block positions', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());
    $manager->registerBlock(new RegisteredBlock('first', 'First', 'core'));
    $manager->registerBlock(new RegisteredBlock('second', 'Second', 'core'));

    // Default idx*50 positions put 'first' (50) before 'second' (100), so
    // the pre-event sortBlocks() call leaves the array in that same
    // order -- the handler then flips the relative order via the public
    // setBlockPosition() API, and only a second, post-event sortBlocks()
    // call can put 'second' back in front.
    $handler = function (BlockManagerPrepareDisplay $event): void {
        $target = $event->value;
        if (! $target instanceof BlockManager) {
            throw new RuntimeException('blockmanager_prepare_display: expected a BlockManager instance');
        }

        $target->setBlockPosition('first', 999);
        $target->setBlockPosition('second', 1);
    };
    EventDispatcherTestFactory::get()->addTypedHandler(BlockManagerPrepareDisplay::class, $handler);

    try {
        $manager->prepareDisplay();
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(BlockManagerPrepareDisplay::class, $handler);
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

    expect($ids)
        ->toBe(['second', 'first']);
});

test('hideBlock removes a previously visible block', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepareDisplay();

    expect($manager->isHidden('cat'))
        ->toBeFalse();
    $manager->hideBlock('cat');
    expect($manager->isHidden('cat'))
        ->toBeTrue();
});

test('setBlockPosition updates the position of a visible block, and is a no-op for an unknown/hidden one', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());
    $manager->registerBlock(new RegisteredBlock('cat', 'Categories', 'core'));
    $manager->prepareDisplay();

    $manager->setBlockPosition('cat', 999);
    $cat = $manager->getBlock('cat');
    if (! $cat instanceof DisplayBlock) {
        throw new RuntimeException('Expected the cat block to remain visible after setBlockPosition()');
    }
    expect($cat->getPosition())
        ->toBe(999);

    // no exception, no-op for a block that was never registered
    $manager->setBlockPosition('does-not-exist', 1);
});

test('getId returns the manager\'s own id', function (): void {
    $manager = new BlockManager('menubar', EventDispatcherTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());

    expect($manager->getId())
        ->toBe('menubar');
});
