<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use Piwigo\Core\HtmlRenderingInterface;

final class ImageServiceTestFakeHtmlRenderer implements HtmlRenderingInterface
{
    public ?string $lastMessage = null;

    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        return '';
    }

    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        return '';
    }

    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        return 0;
    }

    #[\Override]
    public function accessDenied(\Piwigo\Core\RedirectServiceInterface $redirectService): never
    {
        throw new ImageServiceTestFatalSignal('accessDenied');
    }

    #[\Override]
    public function badRequest(\Piwigo\Core\RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new ImageServiceTestFatalSignal('badRequest');
    }

    #[\Override]
    public function pageNotFound(\Piwigo\Core\RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new ImageServiceTestFatalSignal('pageNotFound');
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->lastMessage = $msg;

        throw new ImageServiceTestFatalSignal('fatalError:' . $msg);
    }

    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '';
    }

    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        return '';
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void {}

    #[\Override]
    public function renderElementName(array $info): string
    {
        return '';
    }

    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        return '';
    }

    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        return '';
    }
}
