<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use LogicException;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

/**
 * Test-only HtmlRenderingInterface: turns the `never`-typed
 * badRequest()/fatalError() calls into a catchable exception instead of a
 * real header()+exit() redirect, so the "invalid identifier"/"not found"
 * gates on SearchService's own $htmlRenderer can be observed from a test.
 * Every other method throws too -- none of the scenarios exercised through
 * this fake ever reach tag/category matching (which is the only other
 * HtmlRenderingInterface method SearchService itself calls,
 * tagAlphaCompare()). Named with a SearchServiceTest-specific prefix (not
 * bare FatalSignalHtmlRenderer, matching tests/Integration/SearchServiceTest.php's
 * own name) since SearchServiceTest.php itself has no namespace, unlike the
 * Integration original.
 */
final class SearchServiceTestFatalSignalHtmlRenderer implements HtmlRenderingInterface
{
    /**
     * @param array<int, array<string, mixed>> $catInformations
     */
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('accessDenied called');
    }

    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('badRequest: ' . $msg);
    }

    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('pageNotFound: ' . ($msg ?? ''));
    }

    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new RuntimeException('fatalError: ' . $msg);
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not implemented in this fake');
    }

    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementName(array $info): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }
}
