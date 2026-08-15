<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use LogicException;
use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

/**
 * checkRestrictions()'s only real effect is delegating to
 * HtmlRenderingInterface::accessDenied() -- this fake short-circuits with
 * a distinctive marker exception instead of needing a real
 * RedirectServiceInterface all the way down to a genuine Response/exit
 * path. Every other interface method throws: none of them are reachable
 * through checkRestrictions().
 */
final class CategoryServiceUnitTestFakeHtmlRendererDeniesAccess implements HtmlRenderingInterface
{
    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('CATEGORY_SERVICE_ACCESS_DENIED_MARKER');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function renderElementName(array $info): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not used by checkRestrictions()');
    }
}
