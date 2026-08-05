<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;

final class SrcImageTestFatalRenderer implements HtmlRenderingInterface
{
    public ?string $lastMessage = null;

    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        return '';
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        return 0;
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        return 0;
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new SrcImageTestFatalSignal('accessDenied');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new SrcImageTestFatalSignal('badRequest');
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new SrcImageTestFatalSignal('pageNotFound');
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->lastMessage = $msg;

        throw new SrcImageTestFatalSignal('fatalError:' . $msg);
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '';
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        return '';
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): void {}

    #[Override]
    public function renderElementName(array $info): string
    {
        return '';
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        return '';
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        return '';
    }
}
