<?php

declare(strict_types=1);

namespace Latte\Essential;

/**
 * Latte declares this class `@extends \CachingIterator<mixed, mixed,
 * \Iterator<mixed, mixed>>`, which erases the element type of everything
 * every `{foreach}` in an `$iterator`-using template walks: Latte compiles
 * such a loop to `foreach ($iterator = $ʟ_it = new CachingIterator($rows,
 * …) as $row)`, so `$row` is `mixed` no matter how well `$rows` is typed.
 *
 * That defeats P58's whole premise in the 13 templates that use `$iterator`
 * -- typing a producer's rows changes nothing there, because the type is
 * dropped again at the loop. This stub restores it by making the class
 * generic over what it iterates.
 *
 * Invariant, because PHP's own `\CachingIterator` declares its templates
 * invariant and a covariant one may not be passed through to it. Latte
 * chains nested loops through the `$parent` argument, handing an inner loop
 * the enclosing loop's iterator over a different row type, so that argument
 * and `getParent()` are pinned to `<mixed, mixed>` rather than to this
 * instance's own types -- nothing reads through them anyway; `$iterator`'s
 * useful surface is the `@property-read` list below.
 *
 * Everything below the template lines is Latte's own class docblock, copied
 * verbatim: a stub replaces the real docblock outright, so dropping the
 * `@property-read` list would make `$iterator->counter0` -- the reason a
 * template reaches for `$iterator` in the first place -- an undefined
 * property. Found exactly that way.
 *
 * @template TKey
 * @template TValue
 * @property-read bool $first
 * @property-read bool $last
 * @property-read bool $empty
 * @property-read bool $odd
 * @property-read bool $even
 * @property-read int $counter
 * @property-read int $counter0
 * @property-read mixed $nextKey
 * @property-read mixed $nextValue
 * @property-read self<mixed, mixed>|null $parent
 * @extends \CachingIterator<TKey, TValue, \Iterator<TKey, TValue>>
 * @internal
 */
class CachingIterator extends \CachingIterator implements \Countable
{
    /**
     * @param iterable<TKey, TValue>|\stdClass $iterator
     * @param self<mixed, mixed>|null $parent
     */
    public function __construct(mixed $iterator, ?self $parent = null) {}

    /**
     * @return self<mixed, mixed>|null
     */
    public function getParent(): ?self {}
}
