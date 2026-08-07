<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Html\HtmlService` is L3Presentation, but its own methods have
 * real callers spanning L1Infrastructure (Core/Db/Validation),
 * L2aCoreDomain (Auth/Image/Tag/Category), and L2bExtendedDomain
 * (Search/Section/Url/Calendar/Comment/Notification) -- deptrac's ruleset
 * forbids all of those from depending upward on L3 directly. Lives in
 * `Piwigo\Core` (L1Infrastructure, same direction as `MailerInterface`/
 * `ActivityLoggerInterface`/`FilterUpdaterInterface`) so those classes can
 * depend downward on this instead of the concrete class.
 * `HtmlService implements` it; bound in `config/container.php`.
 *
 * Broader than those 3 siblings (14 methods, not 1-2): unlike
 * `MailerInterface`'s 2 narrowly-scoped consumers, real L1/L2a/L2b callers
 * of `HtmlService` need many different methods from many different
 * call sites (e.g. `Section\SectionPopulator` alone needs 5 of them), so
 * splitting into several micro-interfaces would just mean most L1/L2a/L2b
 * classes implementing/injecting several of them together for no real
 * decoupling benefit. Only methods with a real L1/L2a/L2b caller are
 * included -- the other 9 `HtmlService` methods (L3/L4-only real callers,
 * or event-only) are retargeted/registered directly, no interface needed.
 *
 * `accessDenied()`/`badRequest()`/`pageNotFound()` each take a required
 * `RedirectServiceInterface` parameter -- `HtmlService` itself can't hold
 * one as a constructor dependency (same "hundreds of construction sites"
 * constraint as its own docblock explains for `CategoryRepository`, plus
 * a real deptrac violation: the concrete `RedirectService` is
 * L4Integration, above this L3Presentation class). Every real caller
 * already holds one via its own constructor or can trivially construct a
 * throwaway instance. `pageForbidden()` needs the identical parameter but
 * isn't part of this interface -- its only 2 real callers are
 * L4Integration Controllers with direct concrete-class access already.
 */
interface HtmlRenderingInterface
{
    /**
     * @param array<int, array<string, mixed>> $catInformations
     */
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string;

    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string;

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function nameCompare(array $a, array $b): int;

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function tagAlphaCompare(array $a, array $b): int;

    public function accessDenied(RedirectServiceInterface $redirectService): never;

    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never;

    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never;

    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never;

    /**
     * @param list<array<string, mixed>> $tags
     */
    public function getTagsContentTitle(array $tags): string;

    /**
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string;

    public function setStatusHeader(int $code, string $text = ''): void;

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementName(array $info): string;

    /**
     * @param array<string, mixed> $info
     */
    public function renderElementDescription(array $info, string $param = ''): string;

    /**
     * @param array<string, mixed> $info
     */
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string;
}
