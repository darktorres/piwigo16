<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * A DQL property path paired with whether its column is boolean-typed --
 * {@see \Piwigo\Users\UserInfoField::dqlPropertyAndIsBoolean()}'s own
 * fixed 2-value result.
 */
final readonly class DqlPropertyFlag
{
    public function __construct(
        public string $property,
        public bool $isBoolean,
    ) {}
}
