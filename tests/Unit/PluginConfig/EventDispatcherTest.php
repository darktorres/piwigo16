<?php

declare(strict_types=1);

use Piwigo\PluginConfig\EventDispatcher;

/**
 * Narrows EventDispatcher's private $handlers property (read via
 * Reflection) from ReflectionProperty::getValue()'s mixed return down to
 * its real, internally-guaranteed shape.
 *
 * @return array<mixed, mixed>
 */
function eventDispatcherHandlers(EventDispatcher $dispatcher): array
{
    $reflection = new ReflectionProperty(EventDispatcher::class, 'handlers');
    $handlers = $reflection->getValue($dispatcher);
    if (! is_array($handlers)) {
        throw new RuntimeException('Expected handlers to be an array');
    }

    return $handlers;
}

/**
 * @return array<mixed, mixed>
 */
function eventDispatcherHandlersAt(EventDispatcher $dispatcher, string $event, int $priority): array
{
    $handlers = eventDispatcherHandlers($dispatcher);
    $atEvent = $handlers[$event] ?? null;
    if (! is_array($atEvent)) {
        throw new RuntimeException("Expected handlers[{$event}] to be an array");
    }

    $atPriority = $atEvent[$priority] ?? null;
    if (! is_array($atPriority)) {
        throw new RuntimeException("Expected handlers[{$event}][{$priority}] to be an array");
    }

    return $atPriority;
}

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
    // but real callers (e.g. Admin\Integrity\C13yInternal.php) rely on
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

test('removeEventHandler returns false when the priority bucket has handlers but none match', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');

    expect($dispatcher->removeEventHandler('e', 'strtolower'))->toBeFalse();
    // the non-matching handler must still be registered and callable
    expect($dispatcher->triggerChange('e', 'hello'))->toBe('HELLO');
});

test('set publishes a specific instance that get then returns', function (): void {
    $dispatcher = new EventDispatcher();

    EventDispatcher::set($dispatcher);

    expect(EventDispatcher::get())->toBe($dispatcher);
});

test('triggerChange notifies a registered "trigger" meta-handler before and after the real event fires', function (): void {
    $dispatcher = new EventDispatcher();
    $metaCalls = [];
    $dispatcher->addEventHandler('trigger', static function (mixed $payload) use (&$metaCalls): mixed {
        $metaCalls[] = $payload;

        return $payload;
    });
    $dispatcher->addEventHandler('greet', static fn (string $s): string => $s . '!');

    $result = $dispatcher->triggerChange('greet', 'hi');

    expect($result)->toBe('hi!')
        ->and($metaCalls)->toBe([
            ['type' => 'event', 'event' => 'greet', 'data' => 'hi'],
            ['type' => 'post_event', 'event' => 'greet', 'data' => 'hi!'],
        ]);
});

test('triggerChange include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)->not->toBeFalse();
    file_put_contents($path, '<?php $GLOBALS["event_dispatcher_test_included_change"] = true;');
    $GLOBALS['event_dispatcher_test_included_change'] = false;

    try {
        $dispatcher = new EventDispatcher();
        $dispatcher->addEventHandler('e', 'strtoupper', 50, $path);

        $result = $dispatcher->triggerChange('e', 'hi');

        expect($result)->toBe('HI')
            ->and($GLOBALS['event_dispatcher_test_included_change'])->toBeTrue();
    } finally {
        unlink($path);
        unset($GLOBALS['event_dispatcher_test_included_change']);
    }
});

test('triggerNotify notifies a registered "trigger" meta-handler before calling the real event\'s own handlers', function (): void {
    $dispatcher = new EventDispatcher();
    $metaCalls = [];
    $dispatcher->addEventHandler('trigger', static function (mixed $payload) use (&$metaCalls): void {
        $metaCalls[] = $payload;
    });
    $realCalls = [];
    $dispatcher->addEventHandler('announce', static function () use (&$realCalls): void {
        $realCalls[] = true;
    });

    $dispatcher->triggerNotify('announce');

    expect($metaCalls)->toBe([
        ['type' => 'action', 'event' => 'announce', 'data' => null],
    ])->and($realCalls)->toBe([true]);
});

test('triggerNotify include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)->not->toBeFalse();
    file_put_contents($path, '<?php $GLOBALS["event_dispatcher_test_included_notify"] = true;');
    $GLOBALS['event_dispatcher_test_included_notify'] = false;

    try {
        $dispatcher = new EventDispatcher();
        $dispatcher->addEventHandler('e', static function (): void {}, 50, $path);

        $dispatcher->triggerNotify('e');

        expect($GLOBALS['event_dispatcher_test_included_notify'])->toBeTrue();
    } finally {
        unlink($path);
        unset($GLOBALS['event_dispatcher_test_included_notify']);
    }
});

test('triggerNotify throws only when a dead handler registration is actually invoked', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('dead_event_notify', 'this_function_does_not_exist_anywhere_either');

    $dispatcher->triggerNotify('dead_event_notify');
})->throws(Error::class);

test('addEventHandler appends a new handler alongside others already registered at that priority', function (): void {
    // Kills a CoalesceRemoveLeft mutation on the `?? []` fallback: if the
    // existing $priority bucket were unconditionally discarded instead of
    // reused, only the second-registered handler would survive.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addEventHandler('e', static function () use (&$calls): void {
        $calls[] = 'first';
    });
    $dispatcher->addEventHandler('e', static function () use (&$calls): void {
        $calls[] = 'second';
    });

    $dispatcher->triggerNotify('e');

    expect($calls)->toBe(['first', 'second']);
});

test('removeEventHandler scans past a non-matching handler to reach a later match at the same priority', function (): void {
    // Kills a ContinueToBreak mutation on the loop's `continue`: breaking
    // instead would abandon the scan at the first non-matching handler and
    // never reach -- or remove -- the real target.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtolower');
    $dispatcher->addEventHandler('e', 'strtoupper');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($removed)->toBeTrue()
        ->and($dispatcher->triggerChange('e', 'HeLLo'))->toBe('hello');
});

test('removeEventHandler re-indexes the surviving handlers after removing one from the middle', function (): void {
    // Kills an UnwrapArrayValues mutation on array_values(): without it,
    // removing the middle handler leaves a gapped key structure ([0, 2])
    // rather than a re-indexed list ([0, 1]) -- unobservable through
    // trigger*() alone (foreach preserves insertion order regardless of
    // keys), so this reaches into the private $handlers state directly,
    // same technique as BlockManagerTest's display_blocks assertions.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');
    $dispatcher->addEventHandler('e', 'trim');
    $dispatcher->addEventHandler('e', 'strrev');

    $dispatcher->removeEventHandler('e', 'trim');

    $handlersAt50 = eventDispatcherHandlersAt($dispatcher, 'e', 50);

    expect(array_is_list($handlersAt50))->toBeTrue()
        ->and(array_keys($handlersAt50))->toBe([0, 1]);
});

test('removeEventHandler fully unregisters an event once its only priority bucket becomes empty', function (): void {
    // Kills IfNegated/IdenticalToNotIdentical mutations on
    // `$handlersAtPriority === []`: an inverted/negated condition takes
    // the else branch instead, leaving a stale empty bucket (and the event
    // key) in place rather than unsetting both -- which is invisible to
    // trigger*() (an empty bucket calls no handlers, same as no bucket at
    // all), so this asserts on the private $handlers state directly.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    $handlers = eventDispatcherHandlers($dispatcher);

    expect($removed)->toBeTrue()
        ->and($handlers)->not->toHaveKey('e');
});

test('removeEventHandler reassigns the surviving handlers rather than unsetting the priority bucket', function (): void {
    // Kills the same mutations from the opposite side: with more than one
    // handler still remaining, the (correct) else branch must run instead
    // of the empty-bucket cleanup branch.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');
    $dispatcher->addEventHandler('e', 'trim');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    $handlers = eventDispatcherHandlers($dispatcher);
    $handlersAt50 = eventDispatcherHandlersAt($dispatcher, 'e', 50);

    expect($removed)->toBeTrue()
        ->and($handlers)->toHaveKey('e')
        ->and($handlersAt50)->toHaveCount(1);
});

test('callablesEqual does not reflect a non-Closure removal target when the registered handler is a Closure', function (): void {
    // Kills BooleanAndToBooleanOr (`&&` -> `||`) and an InstanceOfToTrue
    // mutation on the second `instanceof` check: either mutation makes the
    // branch fire here, calling `new ReflectionFunction($b)` on an
    // array-callable -- confirmed live to throw TypeError, since
    // ReflectionFunction only accepts string|Closure, not the
    // array-callable shape a removal target may hold.
    $obj = new class {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (mixed $data): mixed => $data);

    $removed = $dispatcher->removeEventHandler('e', [$obj, 'handle']);

    expect($removed)->toBeFalse();
});

test('callablesEqual does not reflect a non-Closure registered handler when the removal target is a Closure', function (): void {
    // Mirror image of the case above: kills BooleanAndToBooleanOr and an
    // InstanceOfToTrue mutation on the first `instanceof` check by forcing
    // `new ReflectionFunction($a)` on an array-callable registered handler.
    $obj = new class {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', [$obj, 'handle']);

    $removed = $dispatcher->removeEventHandler('e', static fn (mixed $data): mixed => $data);

    expect($removed)->toBeFalse();
});

test('triggerChange skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills an EmptyStringToNotEmpty mutation on the `!== ''` guard: if the
    // guard stopped treating '' as "no path", include_once('') would run
    // and (per phpunit.xml.dist's failOnWarning="true") fail this test on
    // the resulting "Filename cannot be empty"-style warning -- confirmed
    // live that include_once('') does emit that warning rather than
    // throwing, so failOnWarning is what makes it observable here.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (string $s): string => strtoupper($s), 50, '');

    expect($dispatcher->triggerChange('e', 'hi'))->toBe('HI');
});

test('triggerNotify skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills the same mutation on triggerNotify's own copy of the guard.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addEventHandler('e', static function () use (&$calls): void {
        $calls[] = true;
    }, 50, '');

    $dispatcher->triggerNotify('e');

    expect($calls)->toBe([true]);
});
