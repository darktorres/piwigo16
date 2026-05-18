<?php

declare(strict_types=1);

namespace Piwigo\Session;

/**
 * Typed flash-message bag — write-once, read-and-clear on next render.
 *
 * Flash messages have a different lifecycle from session state: they
 * accumulate during request N (writes from controllers, services, event
 * subscribers) and are consumed exactly once on request N+1 (by the
 * renderer that surfaces them in the page header). Conflating them with
 * regular Session slots makes it too easy to forget the "clear after
 * rendering" half of the contract and ship ghost messages.
 *
 * Kinds map to the legacy `$_SESSION['page_infos']` / `page_errors` /
 * `message_tags` keys; the bag persists each kind as a `list<string>`.
 *
 * Composition: `Session` holds a FlashBag, not extends one. The Session VO
 * serializes the bag's underlying state into `$_SESSION` on persist; on
 * hydrate, the bag is reconstructed from those keys.
 */
final class FlashBag
{
    /** @var array<string, list<string>> kind => messages */
    private array $messages = [];

    /** @param array<string, list<string>> $initial */
    private function __construct(array $initial = [])
    {
        $this->messages = $initial;
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @param array<string, list<string>> $messages */
    public static function fromArray(array $messages): self
    {
        return new self($messages);
    }

    public function add(string $kind, string $message): void
    {
        $this->messages[$kind][] = $message;
    }

    /**
     * Read messages of a given kind without consuming them — useful when
     * the renderer wants to peek (e.g. to decide whether to render a wrapper).
     *
     * @return list<string>
     */
    public function peek(string $kind): array
    {
        return $this->messages[$kind] ?? [];
    }

    /**
     * Read messages of a given kind AND clear them. Standard "flash" semantics.
     *
     * @return list<string>
     */
    public function consume(string $kind): array
    {
        $msgs = $this->messages[$kind] ?? [];
        unset($this->messages[$kind]);
        return $msgs;
    }

    public function hasAny(): bool
    {
        return $this->messages !== [];
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->messages;
    }
}
