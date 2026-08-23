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
 * `authKeyId` is request-wide auth-method state (AuthService::
 * tryAuthKeyLogin() sets it, HistoryService::logVisit() reads it),
 * uniform across all of logVisit()'s mutually-exclusive callers -- unlike
 * section/category/tagIds (per-caller gallery-navigation values, threaded
 * as explicit params), any of logVisit()'s callers could equally be
 * reached via an auth-keyed request, so an ambient read here is correct.
 *
 * The page-shell fields (`bodyClasses`/`bodyId`/`pageBanner`/`metaRobots`/
 * `headerNotes`/`headerMessages`) live on `Piwigo\Core\LayoutState`, and
 * the per-request timing/debug/correlation fields (`countQueries`/
 * `queriesTime`/`requestStart`/`debugOutput`/`executionUuid`) live on
 * `Piwigo\Core\RequestMetrics` -- both split out here (P41, docs/PLAN.md)
 * since each is a self-contained cluster with its own small set of real
 * readers/writers, unlike the rest of this class's fields.
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

    public ?int $authKeyId = null;

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
        $this->bodyData = [];
        $this->exposedData = [];
        $this->exposedStringKeys = [];
        $this->authKeyId = null;
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
     * @param array<array-key, mixed>|string|int|float|bool|null $value
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

    public function setAuthKeyId(?int $authKeyId): void
    {
        $this->authKeyId = $authKeyId;
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
