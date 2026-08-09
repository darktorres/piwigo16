<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Url;

use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

final class UrlServiceTestHtmlRenderer implements HtmlRenderingInterface
{
    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[Override]
    public function getCatDisplayNameCache(string $uppercats, ?string $url = '', bool $singleLink = false, ?string $linkClass = null, ?string $authKey = null): string
    {
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
        throw new RuntimeException('accessDenied');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('badRequest: ' . $msg);
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('pageNotFound: ' . $msg);
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new RuntimeException('fatalError: ' . $msg);
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
