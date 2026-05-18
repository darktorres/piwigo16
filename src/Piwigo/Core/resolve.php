<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Container\ContainerInterface;

/**
 * Templated wrapper around `ContainerInterface::get()` that preserves the
 * requested type.
 *
 * PSR-11's `$c->get(string $id): mixed` discards the class-string
 * relationship between argument and return, so every site has to either
 * accept a `mixed` (and trigger Psalm `MixedArgument`/`MixedAssignment`
 * cascades) or hand-suppress the inference.
 *
 * `resolve()` carries the relationship through its `@template` bound:
 * `resolve($c, Connection::class)` is statically a `Connection`. The
 * runtime `assert()` guards against a misconfigured container without
 * adding a release-mode cost (asserts disabled with
 * `zend.assertions=-1`).
 *
 * Use this anywhere `$c->get(SomeClass::class)` would otherwise appear.
 * Typical residue: event-subscriber resolution and `newLazyProxy()`
 * closures that *must* defer resolution to break circular dependencies
 * (eagerly-typed factory class constructors are not viable there).
 *
 * @template T of object
 * @param  class-string<T> $id
 * @return T
 */
function resolve(ContainerInterface $c, string $id): object
{
    $svc = $c->get($id);
    assert($svc instanceof $id, sprintf('Container returned %s for %s', get_debug_type($svc), $id));
    return $svc;
}
