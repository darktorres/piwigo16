<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use LogicException;
use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\HttpStatusLine;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

final class AccessControlTestFakeHtmlRendererDeniesAccess implements HtmlRenderingInterface
{
    public bool $accessDeniedWasCalled = false;

    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function getCatBreadcrumb(string $uppercats): array
    {
        return [];
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        $this->accessDeniedWasCalled = true;
        throw new RuntimeException('ACCESS_CONTROL_ACCESS_DENIED_MARKER');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function renderElementName(array $info): string
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not used by checkStatus()');
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not used by checkStatus()');
    }
}
