<?php

declare(strict_types=1);

use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Fixtures\PluginConfig\TestChangeEvent;
use Piwigo\Tests\Fixtures\PluginConfig\TestNotifyEvent;
use Piwigo\Tests\Support\EventDispatcherTestFactory;

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

function testNotifyHandler(TestNotifyEvent $e): void {}

test('EventDispatcherTestFactory::get lazily builds and reuses the same instance', function (): void {
    $first = EventDispatcherTestFactory::get();
    $second = EventDispatcherTestFactory::get();

    expect($first)
        ->toBe($second);
});

test('addEventHandler registers a nonexistent function name without eagerly validating callability', function (): void {
    // Pre-existing bug this locks in the fix for: include/common.inc.php
    // registers 'pwg_image_resize', a function that doesn't exist anywhere
    // in this codebase, for two events that are never triggered. PHP's
    // native `callable` type hint validates eagerly, which would fatal
    // registration itself -- addEventHandler() does not validate eagerly,
    // only failing (if ever reached) at actual invocation via
    // call_user_func_array().
    $dispatcher = new EventDispatcher();

    $registered = $dispatcher->addEventHandler('dead_event', 'this_function_does_not_exist_anywhere');

    expect($registered)
        ->toBeTrue();
});

test('triggerChange throws only when a dead handler registration is actually invoked', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('dead_event', 'this_function_does_not_exist_anywhere');

    $dispatcher->triggerChange('dead_event', 'data');
})->throws(Error::class);

test('triggerChange returns $data unchanged when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();

    expect($dispatcher->triggerChange('unregistered_event', 'original'))
        ->toBe('original');
});

test('triggerChange passes $data through a single handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('render_tag_url', static fn (string $s): string => strtoupper($s));

    expect($dispatcher->triggerChange('render_tag_url', 'hello'))
        ->toBe('HELLO');
});

test('triggerChange chains handlers in priority order, lowest first', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (string $s): string => $s . 'b', 20);
    $dispatcher->addEventHandler('e', static fn (string $s): string => $s . 'a', 10);

    expect($dispatcher->triggerChange('e', ''))
        ->toBe('ab');
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

    $result = $dispatcher->triggerChange('format_exif_data', null, 'photo.jpg', [
        'a' => 1,
    ]);

    expect($result)
        ->toBe([
            'exif' => null,
            'filename' => 'photo.jpg',
            'map' => [
                'a' => 1,
            ],
        ]);
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

    expect($calls)
        ->toBe(['first', 'second']);
});

test('addEventHandler refuses to register the exact same string callable twice at the same priority', function (): void {
    $dispatcher = new EventDispatcher();

    $first = $dispatcher->addEventHandler('render_tag_url', 'strtolower');
    $second = $dispatcher->addEventHandler('render_tag_url', 'strtolower');

    expect($first)
        ->toBeTrue()
        ->and($second)
        ->toBeFalse();
});

test('addEventHandler refuses a re-evaluated first-class-callable bound to the same object+method', function (): void {
    // $obj->method(...) creates a NEW Closure instance on every evaluation,
    // but real callers (e.g. Admin\Integrity\C13yInternal.php) rely on
    // addEventHandler() recognizing it as the same registration.
    $obj = new class() {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };

    $dispatcher = new EventDispatcher();
    $first = $dispatcher->addEventHandler('e', $obj->handle(...));
    $second = $dispatcher->addEventHandler('e', $obj->handle(...));

    expect($first)
        ->toBeTrue()
        ->and($second)
        ->toBeFalse();
});

test('addEventHandler allows the same method bound to two different objects', function (): void {
    $class = new class() {
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

    expect($first)
        ->toBeTrue()
        ->and($second)
        ->toBeTrue();
});

test('removeEventHandler removes a registered handler and triggerChange no longer calls it', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($removed)
        ->toBeTrue()
        ->and($dispatcher->triggerChange('e', 'hello'))
        ->toBe('hello');
});

test('removeEventHandler returns false for a handler that was never registered', function (): void {
    $dispatcher = new EventDispatcher();

    expect($dispatcher->removeEventHandler('e', 'strtoupper'))
        ->toBeFalse();
});

test('removeEventHandler on one handler leaves sibling handlers at the same priority intact', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');
    $dispatcher->addEventHandler('e', 'trim');

    $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($dispatcher->triggerChange('e', ' hello '))
        ->toBe('hello');
});

test('removeEventHandler returns false when the priority bucket has handlers but none match', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtoupper');

    expect($dispatcher->removeEventHandler('e', 'strtolower'))
        ->toBeFalse();
    // the non-matching handler must still be registered and callable
    expect($dispatcher->triggerChange('e', 'hello'))
        ->toBe('HELLO');
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

    expect($result)
        ->toBe('hi!')
        ->and($metaCalls)
        ->toBe([
            [
                'type' => 'event',
                'event' => 'greet',
                'data' => 'hi',
            ],
            [
                'type' => 'post_event',
                'event' => 'greet',
                'data' => 'hi!',
            ],
        ]);
});

test('triggerChange include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)
        ->not->toBeFalse();
    file_put_contents($path, '<?php $GLOBALS["event_dispatcher_test_included_change"] = true;');
    $GLOBALS['event_dispatcher_test_included_change'] = false;

    try {
        $dispatcher = new EventDispatcher();
        $dispatcher->addEventHandler('e', 'strtoupper', 50, $path);

        $result = $dispatcher->triggerChange('e', 'hi');

        expect($result)
            ->toBe('HI')
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

    expect($metaCalls)
        ->toBe([
            [
                'type' => 'action',
                'event' => 'announce',
                'data' => null,
            ],
        ])->and($realCalls)
        ->toBe([true]);
});

test('triggerNotify include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)
        ->not->toBeFalse();
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

    expect($calls)
        ->toBe(['first', 'second']);
});

test('removeEventHandler scans past a non-matching handler to reach a later match at the same priority', function (): void {
    // Kills a ContinueToBreak mutation on the loop's `continue`: breaking
    // instead would abandon the scan at the first non-matching handler and
    // never reach -- or remove -- the real target.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', 'strtolower');
    $dispatcher->addEventHandler('e', 'strtoupper');

    $removed = $dispatcher->removeEventHandler('e', 'strtoupper');

    expect($removed)
        ->toBeTrue()
        ->and($dispatcher->triggerChange('e', 'HeLLo'))
        ->toBe('hello');
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

    expect(array_is_list($handlersAt50))
        ->toBeTrue()
        ->and(array_keys($handlersAt50))
        ->toBe([0, 1]);
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

    expect($removed)
        ->toBeTrue()
        ->and($handlers)
        ->not->toHaveKey('e');
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

    expect($removed)
        ->toBeTrue()
        ->and($handlers)
        ->toHaveKey('e')
        ->and($handlersAt50)
        ->toHaveCount(1);
});

test('callablesEqual does not reflect a non-Closure removal target when the registered handler is a Closure', function (): void {
    // Kills BooleanAndToBooleanOr (`&&` -> `||`) and an InstanceOfToTrue
    // mutation on the second `instanceof` check: either mutation makes the
    // branch fire here, calling `new ReflectionFunction($b)` on an
    // array-callable, which throws TypeError since
    // ReflectionFunction only accepts string|Closure, not the
    // array-callable shape a removal target may hold.
    $obj = new class() {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (mixed $data): mixed => $data);

    $removed = $dispatcher->removeEventHandler('e', $obj->handle(...));

    expect($removed)
        ->toBeFalse();
});

test('callablesEqual does not reflect a non-Closure registered handler when the removal target is a Closure', function (): void {
    // Mirror image of the case above: kills BooleanAndToBooleanOr and an
    // InstanceOfToTrue mutation on the first `instanceof` check by forcing
    // `new ReflectionFunction($a)` on an array-callable registered handler.
    $obj = new class() {
        public function handle(mixed $data): mixed
        {
            return $data;
        }
    };
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', $obj->handle(...));

    $removed = $dispatcher->removeEventHandler('e', static fn (mixed $data): mixed => $data);

    expect($removed)
        ->toBeFalse();
});

test('triggerChange skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills an EmptyStringToNotEmpty mutation on the `!== ''` guard: if the
    // guard stopped treating '' as "no path", include_once('') would run
    // and (per phpunit.xml.dist's failOnWarning="true") fail this test on
    // the resulting "Filename cannot be empty"-style warning --
    // include_once('') emits that warning rather than
    // throwing, so failOnWarning is what makes it observable here.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler('e', static fn (string $s): string => strtoupper($s), 50, '');

    expect($dispatcher->triggerChange('e', 'hi'))
        ->toBe('HI');
});

test('triggerNotify skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills the same mutation on triggerNotify's own copy of the guard.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addEventHandler('e', static function () use (&$calls): void {
        $calls[] = true;
    }, 50, '');

    $dispatcher->triggerNotify('e');

    expect($calls)
        ->toBe([true]);
});

test('addTypedHandler registers under the event class-string, reaching dispatchChange', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(
        TestChangeEvent::class,
        static function (TestChangeEvent $e): TestChangeEvent {
            $e->value = strtoupper($e->value);

            return $e;
        },
    );
    $event = new TestChangeEvent('hi');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('HI');
});

test('addTypedHandler refuses to register the exact same string callable twice at the same priority', function (): void {
    // Delegates straight to addEventHandler() -- same de-dup contract, no
    // separate implementation to diverge.
    $dispatcher = new EventDispatcher();

    $first = $dispatcher->addTypedHandler(TestNotifyEvent::class, 'testNotifyHandler');
    $second = $dispatcher->addTypedHandler(TestNotifyEvent::class, 'testNotifyHandler');

    expect($first)
        ->toBeTrue()
        ->and($second)
        ->toBeFalse();
});

test('dispatchChange returns the event unchanged when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();
    $event = new TestChangeEvent('original');

    $result = $dispatcher->dispatchChange($event);

    expect($result)
        ->toBe($event)
        ->and($event->value)
        ->toBe('original');
});

test('dispatchChange passes the event through a single handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    });
    $event = new TestChangeEvent('hello');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('HELLO');
});

test('dispatchChange chains handlers in priority order, lowest first', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value .= 'b';

        return $e;
    }, 20);
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value .= 'a';

        return $e;
    }, 10);
    $event = new TestChangeEvent('');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('ab');
});

test('dispatchChange preserves readonly context through a handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = $e->value . '-' . $e->context;

        return $e;
    });
    $event = new TestChangeEvent('v', 'ctx');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('v-ctx')
        ->and($event->context)
        ->toBe('ctx');
});

test('dispatchChange throws when a handler returns something other than an instance of the event class', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static fn (TestChangeEvent $e): ?string => null);

    $dispatcher->dispatchChange(new TestChangeEvent('hi'));
})->throws(Error::class, 'must return an instance of');

test('dispatchChange throws only when a dead handler registration is actually invoked', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler(TestChangeEvent::class, 'this_function_does_not_exist_anywhere');

    $dispatcher->dispatchChange(new TestChangeEvent('hi'));
})->throws(Error::class);

test('dispatchChange include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)
        ->not->toBeFalse();
    file_put_contents($path, '<?php $GLOBALS["event_dispatcher_test_included_dispatch_change"] = true;');
    $GLOBALS['event_dispatcher_test_included_dispatch_change'] = false;

    try {
        $dispatcher = new EventDispatcher();
        $dispatcher->addEventHandler(
            TestChangeEvent::class,
            static function (TestChangeEvent $e): TestChangeEvent {
                $e->value = strtoupper($e->value);

                return $e;
            },
            50,
            $path,
        );

        $event = new TestChangeEvent('hi');
        $dispatcher->dispatchChange($event);

        expect($event->value)
            ->toBe('HI')
            ->and($GLOBALS['event_dispatcher_test_included_dispatch_change'])->toBeTrue();
    } finally {
        unlink($path);
        unset($GLOBALS['event_dispatcher_test_included_dispatch_change']);
    }
});

test('dispatchChange skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills dispatchChange()'s own EmptyStringToNotEmpty mutation on the
    // `!== ''` guard -- same reasoning as triggerChange's own copy of
    // this test above: if the guard stopped treating '' as "no path",
    // include_once('') would run and (per phpunit.xml.dist's
    // failOnWarning="true") fail this test on the resulting "Filename
    // cannot be empty"-style warning.
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    }, 50, '');
    $event = new TestChangeEvent('hi');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('HI');
});

test('dispatchChange notifies a registered "trigger" meta-handler with the event object as data', function (): void {
    $dispatcher = new EventDispatcher();
    $metaCalls = [];
    $dispatcher->addEventHandler('trigger', static function (mixed $payload) use (&$metaCalls): void {
        $metaCalls[] = $payload;
    });
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value .= '!';

        return $e;
    });
    $event = new TestChangeEvent('hi');

    $dispatcher->dispatchChange($event);

    expect($event->value)
        ->toBe('hi!')
        ->and($metaCalls)
        ->toBe([
            [
                'type' => 'event',
                'event' => TestChangeEvent::class,
                'data' => $event,
            ],
            [
                'type' => 'post_event',
                'event' => TestChangeEvent::class,
                'data' => $event,
            ],
        ]);
});

test('dispatchNotify calls every registered handler without transmitting a return value', function (): void {
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'first:' . $e->value;
    });
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'second:' . $e->value;
    }, 60);

    $dispatcher->dispatchNotify(new TestNotifyEvent('hi'));

    expect($calls)
        ->toBe(['first:hi', 'second:hi']);
});

test('dispatchNotify is a no-op when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();

    $dispatcher->dispatchNotify(new TestNotifyEvent('hi'));
})->throwsNoExceptions();

test('dispatchNotify throws only when a dead handler registration is actually invoked', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addEventHandler(TestNotifyEvent::class, 'this_function_does_not_exist_anywhere');

    $dispatcher->dispatchNotify(new TestNotifyEvent('hi'));
})->throws(Error::class);

test('dispatchNotify include_once-s a handler\'s includePath before calling it', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'event_dispatcher_test_');
    expect($path)
        ->not->toBeFalse();
    file_put_contents($path, '<?php $GLOBALS["event_dispatcher_test_included_dispatch_notify"] = true;');
    $GLOBALS['event_dispatcher_test_included_dispatch_notify'] = false;

    try {
        $dispatcher = new EventDispatcher();
        $dispatcher->addEventHandler(TestNotifyEvent::class, static function (): void {}, 50, $path);

        $dispatcher->dispatchNotify(new TestNotifyEvent('hi'));

        expect($GLOBALS['event_dispatcher_test_included_dispatch_notify'])->toBeTrue();
    } finally {
        unlink($path);
        unset($GLOBALS['event_dispatcher_test_included_dispatch_notify']);
    }
});

test('dispatchNotify skips include_once for a handler registered with an empty-string includePath', function (): void {
    // Kills dispatchNotify()'s own EmptyStringToNotEmpty mutation on the
    // `!== ''` guard -- same reasoning as triggerNotify's own copy of
    // this test above.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addEventHandler(TestNotifyEvent::class, static function () use (&$calls): void {
        $calls[] = true;
    }, 50, '');

    $dispatcher->dispatchNotify(new TestNotifyEvent('hi'));

    expect($calls)
        ->toBe([true]);
});

test('dispatchNotify notifies a registered "trigger" meta-handler with type "action" and the event object as data', function (): void {
    $dispatcher = new EventDispatcher();
    $metaCalls = [];
    $dispatcher->addEventHandler('trigger', static function (mixed $payload) use (&$metaCalls): void {
        $metaCalls[] = $payload;
    });
    $event = new TestNotifyEvent('hi');

    $dispatcher->dispatchNotify($event);

    expect($metaCalls)
        ->toBe([
            [
                'type' => 'action',
                'event' => TestNotifyEvent::class,
                'data' => $event,
            ],
        ]);
});
