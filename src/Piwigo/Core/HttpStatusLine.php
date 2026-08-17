<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * The computed HTTP status line `HtmlRenderingInterface::setStatusHeader()`
 * sends via `header()` -- `$code` and the resolved reason `$text` (either
 * the caller-supplied one, or the well-known phrase looked up for $code).
 * Returned rather than discarded so a caller (or a test) can observe what
 * was actually sent without a side channel: `header()` itself is a real
 * no-op under CLI SAPI. Lives in
 * `Piwigo\Core` (L1Infrastructure), same reasoning as
 * `HtmlRenderingInterface` itself -- L1/L2a/L2b callers of
 * `setStatusHeader()` can't depend on a `Piwigo\Html\*` (L3Presentation)
 * return type.
 */
final readonly class HttpStatusLine
{
    public function __construct(
        public int $code,
        public string $text,
    ) {}
}
