<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A page's own tag/date filter UI flags. Each field is nullable because
 * a page entry may omit any of the 3, falling back to the 'default'
 * entry's own value at the caller level -- see
 * Piwigo\Core\PageFilterHelper::getFilterPageValue().
 */
final readonly class PageFilterFlags
{
    public function __construct(
        public ?bool $used,
        public ?bool $cancel,
        public ?bool $addNotes,
    ) {}

    /**
     * @param array<mixed> $entry
     */
    public static function fromArray(array $entry): self
    {
        $used = $entry['used'] ?? null;
        $cancel = $entry['cancel'] ?? null;
        $addNotes = $entry['add_notes'] ?? null;

        return new self(
            used: is_bool($used) ? $used : null,
            cancel: is_bool($cancel) ? $cancel : null,
            addNotes: is_bool($addNotes) ? $addNotes : null,
        );
    }
}
