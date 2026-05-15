<?php

declare(strict_types=1);

namespace Piwigo\Event\Template;

/**
 * Typed event for legacy `combined_script` (dispatch).
 *
 * Dispatched from: src/Piwigo/Template/Template.php (Template::makeScriptSrc)
 */
final readonly class CombinedScript
{
    public function __construct(
        public string $ret,
        public string $script,
    ) {
    }

    public function withRet(string $ret): self
    {
        return new self($ret, $this->script);
    }
}
