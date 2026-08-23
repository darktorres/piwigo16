<?php

declare(strict_types=1);

use Piwigo\Core\SubscriberInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Fixtures\PluginConfig\TestChangeEvent;
use Piwigo\Tests\Fixtures\PluginConfig\TestNotifyEvent;
use Piwigo\Tests\Fixtures\PluginConfig\TestStoppableEvent;
use Piwigo\Tests\Support\EventDispatcherTestFactory;

test('EventDispatcherTestFactory::get lazily builds and reuses the same instance', function (): void {
    $first = EventDispatcherTestFactory::get();
    $second = EventDispatcherTestFactory::get();

    expect($first)
        ->toBe($second);
});

test('removeTypedHandler removes a registered handler and dispatch no longer calls it', function (): void {
    $dispatcher = new EventDispatcher();
    $handler = static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    };
    $dispatcher->addTypedHandler(TestChangeEvent::class, $handler);

    $dispatcher->removeTypedHandler(TestChangeEvent::class, $handler);

    $event = new TestChangeEvent('hello');
    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('hello');
});

test('removeTypedHandler is a safe no-op for a handler that was never registered', function (): void {
    $dispatcher = new EventDispatcher();

    $dispatcher->removeTypedHandler(TestChangeEvent::class, static fn (TestChangeEvent $e): TestChangeEvent => $e);
})->throwsNoExceptions();

test('removeTypedHandler on one handler leaves sibling handlers intact', function (): void {
    $dispatcher = new EventDispatcher();
    $upper = static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    };
    $trim = static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = trim($e->value);

        return $e;
    };
    $dispatcher->addTypedHandler(TestChangeEvent::class, $upper);
    $dispatcher->addTypedHandler(TestChangeEvent::class, $trim);

    $dispatcher->removeTypedHandler(TestChangeEvent::class, $upper);

    $event = new TestChangeEvent(' hello ');
    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('hello');
});

test('removeTypedHandler leaves the registered handler intact when the target does not match', function (): void {
    $dispatcher = new EventDispatcher();
    $upper = static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    };
    $dispatcher->addTypedHandler(TestChangeEvent::class, $upper);

    $dispatcher->removeTypedHandler(TestChangeEvent::class, static fn (TestChangeEvent $e): TestChangeEvent => $e);

    $event = new TestChangeEvent('hello');
    $dispatcher->dispatch($event);

    // the non-matching handler must still be registered and callable
    expect($event->value)
        ->toBe('HELLO');
});

test('removeTypedHandler finds a re-evaluated first-class-callable bound to the same object+method', function (): void {
    // $obj->method(...) creates a NEW Closure instance on every
    // evaluation, but real callers (e.g. Admin\Integrity\
    // C13yInternal.php's own registration pattern) rely on being able to
    // remove a handler using a fresh evaluation of the same bound
    // method -- Symfony's own removeListener() already compares Closures
    // by PHP's native `==` (same bound object + method), not `===`
    // identity, so no dedicated comparison helper is needed here.
    $obj = new class() {
        /**
         * @var list<string>
         */
        public array $calls = [];

        public function handle(TestNotifyEvent $e): void
        {
            $this->calls[] = $e->value;
        }
    };

    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestNotifyEvent::class, $obj->handle(...));

    $dispatcher->removeTypedHandler(TestNotifyEvent::class, $obj->handle(...));

    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($obj->calls)
        ->toBe([]);
});

test('addTypedHandler appends a new handler alongside others already registered at that priority', function (): void {
    // A second registration for the same event must not discard the
    // first -- both run, in registration order (same default priority).
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'first';
    });
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'second';
    });

    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($calls)
        ->toBe(['first', 'second']);
});

test('addTypedHandler registers under the event class-string, reaching dispatch', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(
        TestChangeEvent::class,
        static function (TestChangeEvent $e): TestChangeEvent {
            $e->value = strtoupper($e->value);

            return $e;
        },
    );
    $event = new TestChangeEvent('hi');

    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('HI');
});

test('addTypedHandler allows the same callable to be registered twice, and both firings run', function (): void {
    // Symfony's own addListener() has no dedup -- registering the
    // identical callable twice runs it twice, matching Symfony's native
    // contract exactly.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $handler = static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = $e->value;
    };

    $dispatcher->addTypedHandler(TestNotifyEvent::class, $handler);
    $dispatcher->addTypedHandler(TestNotifyEvent::class, $handler);

    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($calls)
        ->toBe(['hi', 'hi']);
});

// --------------------------------------------------------------- dispatch
//
// The single verb for both use cases: a "change"-style caller reads a
// value back off the same $event reference it dispatched (TestChangeEvent
// below), a "notify"-style caller only cares about the handler's side
// effect (TestNotifyEvent below). Both flow through the exact same
// implementation now, so the plumbing tests (propagation stopping) are
// covered once each rather than once per former method name.

test('dispatch returns the event unchanged when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();
    $event = new TestChangeEvent('original');

    $result = $dispatcher->dispatch($event);

    expect($result)
        ->toBe($event)
        ->and($event->value)
        ->toBe('original');
});

test('dispatch is a no-op when no handler is registered', function (): void {
    $dispatcher = new EventDispatcher();

    $dispatcher->dispatch(new TestNotifyEvent('hi'));
})->throwsNoExceptions();

test('dispatch passes the event through a single handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = strtoupper($e->value);

        return $e;
    });
    $event = new TestChangeEvent('hello');

    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('HELLO');
});

test('dispatch chains handlers in priority order, highest first', function (): void {
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

    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('ba');
});

test('dispatch preserves readonly context through a handler', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestChangeEvent::class, static function (TestChangeEvent $e): TestChangeEvent {
        $e->value = $e->value . '-' . $e->context;

        return $e;
    });
    $event = new TestChangeEvent('v', 'ctx');

    $dispatcher->dispatch($event);

    expect($event->value)
        ->toBe('v-ctx')
        ->and($event->context)
        ->toBe('ctx');
});

test('dispatch calls every registered handler without reading (or requiring) a return value', function (): void {
    // Proves the deleted "handler must return an instance of the event
    // class" guard is really gone: both handlers below return void, which
    // the old dispatchChange() implementation would have rejected outright.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'first:' . $e->value;
    });
    $dispatcher->addTypedHandler(TestNotifyEvent::class, static function (TestNotifyEvent $e) use (&$calls): void {
        $calls[] = 'second:' . $e->value;
    }, 60);

    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($calls)
        ->toBe(['second:hi', 'first:hi']);
});

test('dispatch stops calling further handlers once a handler stops propagation', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestStoppableEvent::class, static function (TestStoppableEvent $e): void {
        $e->calls[] = 'first';
    }, 30);
    $dispatcher->addTypedHandler(TestStoppableEvent::class, static function (TestStoppableEvent $e): void {
        $e->calls[] = 'second';
        $e->stop();
    }, 20);
    $dispatcher->addTypedHandler(TestStoppableEvent::class, static function (TestStoppableEvent $e): void {
        $e->calls[] = 'third';
    }, 10);
    $event = new TestStoppableEvent();

    $dispatcher->dispatch($event);

    expect($event->calls)
        ->toBe(['first', 'second']);
});

test('dispatch runs every handler when the event never stops propagation', function (): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addTypedHandler(TestStoppableEvent::class, static function (TestStoppableEvent $e): void {
        $e->calls[] = 'first';
    }, 20);
    $dispatcher->addTypedHandler(TestStoppableEvent::class, static function (TestStoppableEvent $e): void {
        $e->calls[] = 'second';
    }, 10);
    $event = new TestStoppableEvent();

    $dispatcher->dispatch($event);

    expect($event->calls)
        ->toBe(['first', 'second']);
});

test('registerSubscriber wires a single-event subscriber onto the dispatcher', function (): void {
    $subscriber = new class() implements SubscriberInterface {
        /**
         * @var list<string>
         */
        public array $calls = [];

        #[\Override]
        public function subscribedEvents(): array
        {
            return [
                TestNotifyEvent::class => $this->onNotify(...),
            ];
        }

        public function onNotify(TestNotifyEvent $e): void
        {
            $this->calls[] = $e->value;
        }
    };
    $dispatcher = new EventDispatcher();

    $dispatcher->registerSubscriber($subscriber);
    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($subscriber->calls)
        ->toBe(['hi']);
});

test('registerSubscriber wires every closure in a list<Closure> entry for the same event', function (): void {
    $subscriber = new class() implements SubscriberInterface {
        /**
         * @var list<string>
         */
        public array $calls = [];

        #[\Override]
        public function subscribedEvents(): array
        {
            return [
                TestNotifyEvent::class => [$this->first(...), $this->second(...)],
            ];
        }

        public function first(TestNotifyEvent $e): void
        {
            $this->calls[] = 'first:' . $e->value;
        }

        public function second(TestNotifyEvent $e): void
        {
            $this->calls[] = 'second:' . $e->value;
        }
    };
    $dispatcher = new EventDispatcher();

    $dispatcher->registerSubscriber($subscriber);
    $dispatcher->dispatch(new TestNotifyEvent('hi'));

    expect($subscriber->calls)
        ->toBe(['first:hi', 'second:hi']);
});

test('registerSubscriber wires every event class a subscriber declares, not just the first', function (): void {
    $subscriber = new class() implements SubscriberInterface {
        public bool $notifyCalled = false;

        public ?string $changeValue = null;

        #[\Override]
        public function subscribedEvents(): array
        {
            return [
                TestNotifyEvent::class => $this->onNotify(...),
                TestChangeEvent::class => $this->onChange(...),
            ];
        }

        public function onNotify(TestNotifyEvent $e): void
        {
            $this->notifyCalled = true;
        }

        public function onChange(TestChangeEvent $e): TestChangeEvent
        {
            $this->changeValue = $e->value;
            $e->value = strtoupper($e->value);

            return $e;
        }
    };
    $dispatcher = new EventDispatcher();

    $dispatcher->registerSubscriber($subscriber);
    $dispatcher->dispatch(new TestNotifyEvent('hi'));
    $changeEvent = new TestChangeEvent('hello');
    $dispatcher->dispatch($changeEvent);

    expect($subscriber->notifyCalled)
        ->toBeTrue()
        ->and($subscriber->changeValue)
        ->toBe('hello')
        ->and($changeEvent->value)
        ->toBe('HELLO');
});
