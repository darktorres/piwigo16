<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8f-3: `Piwigo\Html\HtmlService` is L3Presentation, but its own
 * free-function delegates (formerly include/functions_html.inc.php) have
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

    public function accessDenied(): never;

    public function badRequest(string $msg, ?string $alternateUrl = null): never;

    public function pageNotFound(?string $msg, ?string $alternateUrl = null): never;

    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never;

    public function getTagsContentTitle(): string;

    public function getCombinedCategoriesContentTitle(): string;

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
