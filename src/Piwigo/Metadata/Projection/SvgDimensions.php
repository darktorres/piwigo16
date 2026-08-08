<?php

declare(strict_types=1);

namespace Piwigo\Metadata\Projection;

/**
 * {@see \Piwigo\Metadata\MetadataService::parseSvgDimensions()}'s own
 * fixed 2-value result, previously a bare `[int, int]` tuple.
 */
final readonly class SvgDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}
}
