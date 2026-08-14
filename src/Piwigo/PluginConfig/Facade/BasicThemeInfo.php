<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Common\ValueObject\ThemeId;

/**
 * One row of {@see ThemeReadFacade::listBasic()}'s own listing.
 */
final readonly class BasicThemeInfo
{
    public function __construct(
        public ThemeId $id,
        public ?string $name,
    ) {}
}
