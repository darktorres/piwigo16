<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed reader/writer for the per-request page-shell (header/layout)
 * state -- split out of `PageState` (P41, docs/PLAN.md) since these 6
 * fields are read only by `Piwigo\Page\PageHeaderRenderer` (plus
 * `headerNotes`, also merged in by `Bootstrap\RequestBootstrap`, and
 * `headerMessages`, also drained by it into
 * `Bootstrap\Projection\HeaderMessagesPageContext`), unlike the rest of
 * `PageState`'s genuinely-unrelated fields.
 *
 * Lives in `Piwigo\Core` (L1Infrastructure), not `Piwigo\Page`
 * (L3Presentation): `Filter\FilterService`/`Section\SectionPopulator`
 * (both L2bExtendedDomain) write to it directly, and L2b may only
 * depend downward on L1Infrastructure, not sideways/upward on
 * L3Presentation -- the same reasoning `PageState` itself already sits
 * in `Piwigo\Core`, not `Piwigo\Page`, despite `PageHeaderRenderer`
 * being one of its own readers too.
 *
 * Container-shared; a zero-arg public constructor needs no
 * `container.php` entry, same as `PageState` itself.
 */
final class LayoutState
{
    /**
     * @var list<string>
     */
    public array $bodyClasses = [];

    /**
     * The CSS id assigned to the page's `<body>` tag, set by whichever
     * controller handled the request (each assigns its own fixed
     * literal, e.g. 'theCategoryPage'/'theProfilePage') and read once by
     * PageHeaderRenderer.
     */
    public string $bodyId = '';

    /**
     * Nullable (not '') so PageHeaderRenderer's `?? CurrentConfig::pageBanner()`
     * fallback still works: AdminShell/AdminPopuphelpController set a real
     * banner string, but PopuphelpController deliberately sets '' to mean
     * "no banner", which must NOT fall back to the configured default.
     */
    public ?string $pageBanner = null;

    /**
     * @var array<string, int>
     */
    public array $metaRobots = [];

    /**
     * @var list<string>
     */
    public array $headerNotes = [];

    /**
     * @var list<string>
     */
    public array $headerMessages = [];

    public function __construct() {}

    /**
     * Test-only -- re-initializes every property back to its constructed
     * default, same reasoning as `PageState`'s own reset() docblock.
     */
    public function reset(): void
    {
        $this->bodyClasses = [];
        $this->bodyId = '';
        $this->pageBanner = null;
        $this->metaRobots = [];
        $this->headerNotes = [];
        $this->headerMessages = [];
    }

    public function addHeaderMessage(string $message): void
    {
        $this->headerMessages[] = $message;
    }

    public function addHeaderNote(string $note): void
    {
        $this->headerNotes[] = $note;
    }

    public function setBodyId(string $id): void
    {
        $this->bodyId = $id;
    }

    public function setPageBanner(string $banner): void
    {
        $this->pageBanner = $banner;
    }

    /**
     * Bulk-replaces the current meta-robots flags -- matches every real
     * writer's own shape (SectionPopulator::computeMetaRobots()'s return
     * value, or a plain literal `['noindex' => 1, 'nofollow' => 1]` from
     * PictureController/PopuphelpController/AdminPopuphelpController/
     * NotificationController).
     *
     * @param array<string, int> $flags
     */
    public function setMetaRobots(array $flags): void
    {
        $this->metaRobots = $flags;
    }

    /**
     * Adds a single meta-robots flag without disturbing any already set --
     * GalleryController's own `?display=` override and
     * PageHeaderRenderer's own `CurrentConfig::metaRef()` gate both need this
     * incremental shape rather than a full replace.
     */
    public function setMetaRobotsFlag(string $name): void
    {
        $this->metaRobots[$name] = 1;
    }
}
