<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use LogicException;
use Override;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * Fake for deleteElementFiles()'s own UrlServiceInterface parameter --
 * only urlIsRemote() is real call surface (both directly, and via
 * ImagePathHelper::getElementPath()); every other method is unreachable
 * through this path and throws.
 */
final class ImageServiceTestFakeUrlService implements UrlServiceInterface
{
    #[Override]
    public function getRootUrl(): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function makeIndexUrl(array $params = []): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function makePictureUrl(array $params): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function parseSectionUrl(array $tokens, &$nextToken, RedirectServiceInterface $redirectService): array
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function setMakeFullUrl(): void {}

    #[Override]
    public function unsetMakeFullUrl(): void {}

    #[Override]
    public function embellishUrl(string $url): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function getGalleryHomeUrl(): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new LogicException('not used');
    }

    #[Override]
    public function urlIsRemote(string $url): bool
    {
        return str_starts_with($url, 'https://remote.example.test/');
    }

    #[Override]
    public function getUserFavorites(): array
    {
        throw new LogicException('not used');
    }
}
