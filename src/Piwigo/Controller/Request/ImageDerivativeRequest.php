<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

/**
 * Validated request shape for ImageDerivativeController (replaces i.php)
 * -- P27/SEC-40 Request DTO. `b` is a plain cache-busting presence flag
 * (its value, if any, is never read); `ajaxload` is checked for the exact
 * literal `true`. Neither needs InputValidator pattern validation.
 */
final readonly class ImageDerivativeRequest
{
    private function __construct(
        public bool $hasCacheBust,
        public bool $isAjaxLoad,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        return new self(isset($source['b']), ($source['ajaxload'] ?? null) === 'true');
    }
}
