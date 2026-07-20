<?php

declare(strict_types=1);

use Piwigo\PluginConfig\EventDispatcher;

beforeEach(function (): void {
    EventDispatcher::reset();
});

afterEach(function (): void {
    EventDispatcher::reset();
});

test('get() lazily builds and reuses the same instance', function (): void {
    $first = EventDispatcher::get();
    $second = EventDispatcher::get();

    expect($first)->toBe($second);
});

test('addEventHandler registers a nonexistent function name without eagerly validating callability', function (): void {
    // Real pre-existing bug this locks in the fix for: include/common.inc.php
    // (formerly admin/include/functions_upload.inc.php, relocated in P23
    // sub-batch 8b-3) registers 'pwg_image_resize', a function
    // that doesn't exist anywhere in this codebase, for two events that
    // are never triggered. PHP's native `callable` type hint validates
    // eagerly, which would fatal registration itself -- the original
    // `global $pwg_event_handlers` array never did, only failing (if ever
    // reached) at actual invocation via call_user_func_array().
    $dispatcher = new EventDispatcher();

    $registered = $dispatcher->addEventHandler('dead_event', 'this_function_does_not_exist_anywhere');

    expect($registered)->toBeTrue();
});

test('triggerChange throws only when a dead handler registration is actually invoked', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('dead_event', 'this_function_does_not_exist_anywhere');

    $dispatcher->triggerChange('dead_event', 'data');
})->throws(Error::class);

test('triggerChange returns $data unchanged when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();

    expect($dispatcher->triggerChange('unregistered_event', 'original'))->toBe('original');
});

test('triggerChange passes $data through a single handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('render_tag_url', static fn (string $s): string => strtoupper($s));

    expect($dispatcher->triggerChange('render_tag_url', 'hello'))->toBe('HELLO');
});

test('triggerChange chains handlers in priority order, lowest first', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (string $s): string => $s . 'b', 20);
    $dispatcher->addEventHandler('e', static fn (string $s): string => $s . 'a', 10);

    expect($dispatcher->triggerChange('e', ''))->toBe('ab');
});

test('triggerChange forwards extra args unchanged to every handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler(
        'format_exif_data',
        static fn (?array $exif, string $filename, array $map): array => [
            'exif' => $exif,
            'filename' => $filename,
            'map' => $map,
        ],
    );

    $result = $dispatcher->triggerChange('format_exif_data', null, 'photo.jpg', ['a' => 1]);

    expect($result)->toBe(['exif' => null, 'filename' => 'photo.jpg', 'map' => ['a' => 1]]);
});

test('triggerNotify calls every registered handler without transmitting a return value', function (): void {
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addEventHandler('plugins_loaded', static function () use (&$calls): void {
        $calls[] = 'first';
    });
    $dispatcher->addEventHandler('plugins_loaded', static function () use (&$calls): void {
        $calls[] = 'second';
    }, 60);

    $dispatcher->triggerNotify('plugins_loaded');

    expect($calls)->toBe(['first', 'second']);
});

test('addEventHandler refuses to register the exact same string callable twice at the same priority', function (): void {
    $dispatcher = new EventDispatcher();

    $first = $dispatcher->addEventHandler('render_tag_url', 'strtolower');
    $second = $dispatcher->addEventHandler('render_tag_url', 'strtolower');

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});

test('addEventHandler refuses a re-evaluated first-class-callable bound to the same object+method', function (): void {
    // $obj->method(...) creates a NEW Closure instance on every evaluation,
    // but real callers (e.g. Admin\Integrity\c13y_internal.php) rely on
    // addEventHandler() recognizing it as the same registration.
    $obj = new class {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };

    $dispatcher = new EventDispatcher();
    $first = $dispatcher->addEventHandler('e', $obj->handle(...));
    $second = $dispatcher->addEventHandler('e', $obj->handle(...));

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});

test('addEventHandler allows the same method bound to two different objects', function (): void {
    $class = new class {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };
    $objA = clone $class;
    $objB = clone $class;

    $dispatcher = new EventDispatcher();
    $first = $dispatcher->addEventHandler('e', $objA->handle(...));
    $second = $dispatcher->addEventHandler('e', $objB->handle(...));

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue();
});

test('removeEventHandler removes a registered handler and triggerChange no longer calls it', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($removed)->toBeTrue()
        ->and($dispatcher->triggerChange('e', 'hello'))->toBe('hello');
});

test('removeEventHandler returns false for a handler that was never registered', function (): void {
    $dispatcher = new EventDispatcher();

    expect($dispatcher->removeEventHandler('e', 'strtoupper'))->toBeFalse();
});

test('removeEventHandler on one handler leaves sibling handlers at the same priority intact', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');
    $dispatcher->addEventHandler('e', 'trim');

    $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($dispatcher->triggerChange('e', ' hello '))->toBe('hello');
});
