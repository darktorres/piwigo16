<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

use LogicException;
use Override;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * getRootUrl() returns '' and embellishUrl() is the identity function, so
 * get_url() callers below can assert on the exact rel_url build() computed
 * with no extra prefix/suffix to account for.
 */
final class DerivativeImageTestFakeUrlService implements UrlServiceInterface
{
    public function __construct(
        private readonly string $rootUrl = ''
    ) {}

    #[Override]
    public function getRootUrl(): string
    {
        return $this->rootUrl;
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
        return $url;
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
        return false;
    }

    #[Override]
    public function getUserFavorites(): array
    {
        throw new LogicException('not used');
    }
}
