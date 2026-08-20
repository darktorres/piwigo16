<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Core\Projection\ApiKeyExpirationNotice;

/**
 * Typed reader/writer for the per-request page state.
 *
 * Container-shared; a zero-arg public constructor needs no
 * `container.php` entry.
 *
 * `metaRobots` follows the same "computed early by one of several
 * controllers, consumed once by PageHeaderRenderer at final page-render
 * time" shape as bodyClasses/bodyData -- SectionPopulator computes it for
 * gallery/picture pages, but PictureController/PopuphelpController/
 * AdminPopuphelpController/NotificationController each set it
 * independently for their own non-gallery pages, so it belongs here
 * rather than on SectionContext, which only exists for the
 * gallery-navigation-context cluster.
 *
 * `authKeyId` is request-wide auth-method state (AuthService::
 * tryAuthKeyLogin() sets it, HistoryService::logVisit() reads it),
 * uniform across all of logVisit()'s mutually-exclusive callers -- unlike
 * section/category/tagIds (per-caller gallery-navigation values, threaded
 * as explicit params), any of logVisit()'s callers could equally be
 * reached via an auth-keyed request, so an ambient read here is correct.
 *
 * `countQueries`/`queriesTime` are a running per-request accumulator;
 * TimingHelper/PageTailRenderer read the running total.
 */
final class PageState
{
    /**
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @var list<string>
     */
    public array $messages = [];

    /**
     * @var list<string>
     */
    public array $infos = [];

    /**
     * @var list<string>
     */
    public array $headerMessages = [];

    /**
     * @var list<string>
     */
    public array $headerNotes = [];

    /**
     * @var list<string>
     */
    public array $bodyClasses = [];

    /**
     * Plain unstructured key/value bag, same by-design shape as
     * Piwigo\Core\ProcessCache. SectionPopulator::computeSectionContext()
     * is the one real writer (combined_category_ids/tag_ids/search_id/
     * image_id), bulk-replacing this on every gallery/picture page;
     * PageHeaderRenderer read it back into the legacy `BODY_DATA` context
     * var until P37 (docs/PLAN.md), which folds it into
     * Piwigo\Page\PageDataPayload's typed `data` payload instead --
     * $exposedData below is a separate, merged-in property rather than a
     * second writer of this same array, so correctness here never
     * depends on write ordering between the two.
     *
     * @var array<string, mixed>
     */
    public array $bodyData = [];

    /**
     * Typed-page-data-exposure `data` half (docs/PLAN.md's P37) --
     * populated via exposeData() from anywhere in a request (controller
     * or, during P38's incremental template migration, a template
     * itself), merged with $bodyData into one flat object by
     * Piwigo\Page\PageDataPayload. Kept separate from $bodyData itself
     * deliberately -- see that property's own docblock.
     *
     * @var array<string, string|int|float|bool|null|array<mixed>>
     */
    public array $exposedData = [];

    /**
     * Typed-page-data-exposure `strings` half (docs/PLAN.md's P37) --
     * the set of translation keys (raw source strings, gettext's own
     * convention) a page's client JS needs, declared via exposeString()
     * from anywhere in a request. Keyed by the string itself so a key
     * exposed more than once (e.g. from two different template
     * partials) naturally dedupes; Piwigo\Page\PageDataPayload resolves
     * each key through Piwigo\Core\Lang::t() at payload-build time.
     *
     * @var array<string, true>
     */
    public array $exposedStringKeys = [];

    public string $executionUuid = '';

    /**
     * @var array<string, int>
     */
    public array $metaRobots = [];

    public ?int $authKeyId = null;

    public int $countQueries = 0;

    public float $queriesTime = 0.0;

    /**
     * The instant (`microtime(true)`) this request began. Captured at
     * `include/common.inc.php`'s top-level scope, before the autoload
     * boundary, for maximum precision, and handed off here early in
     * `RequestBootstrap::configure()`'s body (right after its own
     * `Kernel::boot()` call); every other consumer reads it from here.
     */
    public float $requestStart = 0.0;

    /**
     * Accumulated debug-mode query/timing HTML, shown in the page footer.
     * Only populated when CurrentConfig::showQueries() is on.
     */
    public string $debugOutput = '';

    /**
     * The CSS id assigned to the page's `<body>` tag, set by whichever of
     * 13 controllers handled the request (each assigns its own fixed
     * literal, e.g. 'theCategoryPage'/'theProfilePage') and read once by
     * PageHeaderRenderer. Ambient like metaRobots/bodyClasses -- no
     * per-caller variation, just "whichever controller ran, set this once."
     */
    public string $bodyId = '';

    /**
     * Nullable (not '') so PageHeaderRenderer's `?? CurrentConfig::pageBanner()`
     * fallback still works: AdminShell/AdminPopuphelpController set a real
     * banner string, but PopuphelpController deliberately sets '' to mean
     * "no banner", which must NOT fall back to the configured default.
     */
    public ?string $pageBanner = null;

    public ?int $nbPendingComments = null;

    public ?int $noMd5sumNumber = null;

    public int $nbOrphans = 0;

    public int $nbPhotosTotal = 0;

    public ?string $updatedVersion = null;

    public bool $authKeyInvalid = false;

    public ?ApiKeyExpirationNotice $notifyApiKeyExpiration = null;

    /**
     * Comment domain's own anti-spam rejection reasons, accumulated here
     * rather than via a request superglobal.
     *
     * @var list<string>
     */
    public array $commentRejectionReasons = [];

    public function __construct() {}

    /**
     * Test-only -- re-initializes every property back to its constructed
     * default. Unlike DeploymentPolicy/StorageRegistry (which carry no
     * real per-request-mutable state once container-shared), this class
     * genuinely accumulates real state across a request (add*() calls
     * from one caller, read back by another later), so test isolation
     * still needs a real reset, same as SessionService/FilterState/
     * CurrentLogger's own instance reset() methods.
     */
    public function reset(): void
    {
        $this->errors = [];
        $this->warnings = [];
        $this->messages = [];
        $this->infos = [];
        $this->headerMessages = [];
        $this->headerNotes = [];
        $this->bodyClasses = [];
        $this->bodyData = [];
        $this->exposedData = [];
        $this->exposedStringKeys = [];
        $this->executionUuid = '';
        $this->metaRobots = [];
        $this->authKeyId = null;
        $this->countQueries = 0;
        $this->queriesTime = 0.0;
        $this->requestStart = 0.0;
        $this->debugOutput = '';
        $this->bodyId = '';
        $this->pageBanner = null;
        $this->nbPendingComments = null;
        $this->noMd5sumNumber = null;
        $this->nbOrphans = 0;
        $this->nbPhotosTotal = 0;
        $this->updatedVersion = null;
        $this->authKeyInvalid = false;
        $this->notifyApiKeyExpiration = null;
        $this->commentRejectionReasons = [];
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function addInfo(string $message): void
    {
        $this->infos[] = $message;
    }

    public function addCommentRejectionReason(string $reason): void
    {
        $this->commentRejectionReasons[] = $reason;
    }

    public function addHeaderMessage(string $message): void
    {
        $this->headerMessages[] = $message;
    }

    public function addHeaderNote(string $note): void
    {
        $this->headerNotes[] = $note;
    }

    public function addBodyClass(string $class): void
    {
        $this->bodyClasses[] = $class;
    }

    public function setBodyData(string $key, mixed $value): void
    {
        $this->bodyData[$key] = $value;
    }

    /**
     * Declares a value the page's typed JSON-data-island payload
     * (docs/PLAN.md's P37) should carry under $key. Deliberately typed
     * as a plain JSON-primitive shape, not `mixed` -- see this class's
     * own $exposedData docblock and Piwigo\Page\PageDataPayload's: this
     * value is read later (at payload-build time, not here), so an
     * object would be stored by reference and could reflect a *later*
     * mutation instead of the value true at declaration time.
     *
     * @param array<mixed> $value
     */
    public function exposeData(string $key, string|int|float|bool|null|array $value): void
    {
        $this->exposedData[$key] = $value;
    }

    /**
     * Declares a translation key the page's client JS needs -- resolved
     * later, via Piwigo\Core\Lang::t(), into the JSON-data-island
     * payload's `strings` map (docs/PLAN.md's P37).
     */
    public function exposeString(string $translationKey): void
    {
        $this->exposedStringKeys[$translationKey] = true;
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

    public function setAuthKeyId(?int $authKeyId): void
    {
        $this->authKeyId = $authKeyId;
    }

    /**
     * Accumulates both counters together. No current call site invokes
     * this method in production (DB access goes through
     * `Piwigo\Db\DbConnection`), so `countQueries`/`queriesTime` stay at 0
     * outside of tests that call it directly.
     */
    public function addQueryTime(float $time): void
    {
        $this->countQueries++;
        $this->queriesTime += $time;
    }

    public function addDebugOutput(string $line): void
    {
        $this->debugOutput .= $line;
    }

    public function setBodyId(string $id): void
    {
        $this->bodyId = $id;
    }

    public function setPageBanner(string $banner): void
    {
        $this->pageBanner = $banner;
    }

    public function setNbPendingComments(int $count): void
    {
        $this->nbPendingComments = $count;
    }

    public function setNoMd5sumNumber(int $count): void
    {
        $this->noMd5sumNumber = $count;
    }

    public function setNbOrphans(int $count): void
    {
        $this->nbOrphans = $count;
    }

    public function setNbPhotosTotal(int $count): void
    {
        $this->nbPhotosTotal = $count;
    }

    public function setUpdatedVersion(string $version): void
    {
        $this->updatedVersion = $version;
    }

    public function markAuthKeyInvalid(): void
    {
        $this->authKeyInvalid = true;
    }

    public function setNotifyApiKeyExpiration(ApiKeyExpirationNotice $data): void
    {
        $this->notifyApiKeyExpiration = $data;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
