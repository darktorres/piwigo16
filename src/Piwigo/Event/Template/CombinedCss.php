<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `combined_css` (dispatch).
 *
 * Dispatched from: src/Piwigo/Template/Template.php
 */
final readonly class CombinedCss
{
    public function __construct(
        public string $href,
        public \Piwigo\Template\Combinable $combinable,
    ) {
    }

    public function withHref(string $href): self
    {
        return new self($href, $this->combinable);
    }
}
