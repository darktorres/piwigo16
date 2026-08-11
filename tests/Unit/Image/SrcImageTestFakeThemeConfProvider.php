<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Override;
use Piwigo\Core\ThemeConfProviderInterface;

final readonly class SrcImageTestFakeThemeConfProvider implements ThemeConfProviderInterface
{
    public function __construct(
        private string $mimeIconDir
    ) {}

    #[Override]
    public function themeConf(string $key): string
    {
        return $key === 'mime_icon_dir' ? $this->mimeIconDir : '';
    }
}
