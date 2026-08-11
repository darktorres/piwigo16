<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Validation;

use LogicException;
use Override;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use RuntimeException;

/**
 * Records whether fatalError() was actually delegated to by
 * InputValidator::fatalError() -- distinct from InputValidator's own
 * fallback `throw new \RuntimeException($msg)`, which fires regardless of
 * whether a renderer is installed. Only this fake's own invocation proves
 * the `self::$htmlRenderer instanceof HtmlRenderingInterface` guard
 * actually ran true (see InputValidatorTest.php's InstanceOfToFalse test).
 */
final class InputValidatorTestFakeHtmlRenderer implements HtmlRenderingInterface
{
    public bool $fatalErrorWasCalled = false;

    public ?string $lastMessage = null;

    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        $this->fatalErrorWasCalled = true;
        $this->lastMessage = $msg;

        throw new RuntimeException($msg);
    }

    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function renderElementName(array $info): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }

    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new LogicException('not used by InputValidator::fatalError()');
    }
}
