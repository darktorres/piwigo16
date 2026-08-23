<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use LogicException;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\HttpStatusLine;
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
    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

    #[\Override]
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
    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not implemented in this fake');
    }

    #[\Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new RuntimeException('accessDenied called');
    }

    #[\Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('badRequest: ' . $msg);
    }

    #[\Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new RuntimeException('pageNotFound: ' . ($msg ?? ''));
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new RuntimeException('fatalError: ' . $msg);
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not implemented in this fake');
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function renderElementName(array $info): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }

    /**
     * @param array<string, mixed> $info
     */
    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not implemented in this fake');
    }
}
