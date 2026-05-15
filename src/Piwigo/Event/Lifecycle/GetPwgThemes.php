<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `get_pwg_themes` (dispatch).
 *
 * Dispatched from: src/Piwigo/Core/Util.php
 */
final readonly class GetPwgThemes
{
    /**
     * @param array<mixed> $themes
     */
    public function __construct(
        public array $themes,
    ) {
    }

    /**
     * @param array<mixed> $themes
     */
    public function withThemes(array $themes): self
    {
        return new self($themes);
    }
}
