<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Typed reader/writer for the per-request page state.
 *
 * `attachGlobals()` reference-bridges the well-known `$GLOBALS['page']` keys
 * (errors/warnings/messages/infos/body_classes/body_data/execution_uuid/
 * meta_robots, all seeded by include/common.inc.php) plus the standalone
 * `$GLOBALS['header_msgs']`/`$GLOBALS['header_notes']` globals (also real,
 * actively written/read -- see common.inc.php/RequestBootstrap,
 * FilterService::initializeFromRequest(), PageHeaderRenderer) onto this
 * singleton's properties. Once bridged, both
 * sides share the same underlying storage: `$page['errors'][] = 'msg'`
 * from legacy code and `PageState::current()->addError('msg')` from new
 * code are the same write, not two copies that can diverge.
 *
 * Deliberately no `keyedErrors`/`setKeyedError()` (present in the plan
 * doc's inline sketch) -- no legacy correspondent and no real caller exist
 * yet; a per-field validation shape like that belongs with whichever
 * P17-23 phase first builds real form validation.
 *
 * `metaRobots` (Legacy Coupling Retirement Track A batch A5.2g) is the
 * same "computed early by one of several controllers, consumed once by
 * PageHeaderRenderer at final page-render time" shape as bodyClasses/
 * bodyData -- SectionPopulator computes it for gallery/picture pages,
 * but PictureController/PopuphelpController/AdminPopuphelpController/
 * NotificationController each set it independently for their own
 * non-gallery pages, so it belongs here rather than on SectionContext
 * (which only exists for the gallery-navigation-context cluster, B1).
 *
 * `authKeyId` (batch A5.2h) is request-wide auth-method state
 * (AuthService::tryAuthKeyLogin() sets it, HistoryService::logVisit()
 * reads it), uniform across all 3 of logVisit()'s mutually-exclusive
 * callers -- unlike section/category/tagIds (real per-caller gallery-
 * navigation values, threaded as explicit params), any of logVisit()'s
 * callers could equally be reached via an auth-keyed request, so an
 * ambient read here is correct, not a repeat of that same-shaped
 * mistake.
 *
 * `countQueries`/`queriesTime` (batch A5.2h) are a running per-request
 * accumulator incremented by MysqliDb::query() on every database query;
 * TimingHelper/PageTailRenderer read the running total, UpgradeRunner
 * resets both to 0 as a checkpoint before a version-upgrade step so its
 * own displayed total reflects only that step's queries. No
 * `attachGlobals()` bridging for `authKeyId`/`countQueries`/
 * `queriesTime` -- confirmed via a repo-wide grep that every real
 * reader/writer is retargeted in this same batch, so there is no
 * remaining legacy consumer left to bridge for.
 *
 * `bodyId`/`pageBanner`/`nbPendingComments`/`noMd5sumNumber`/
 * `updatedVersion`/`authKeyInvalid`/`notifyApiKeyExpiration` (batch
 * A5.2i) were found by a final exhaustive repo-wide `$page` sweep done
 * while closing out Track A5.2 Phase B -- small single-writer-or-few
 * -writers/single-reader clusters missed by the original B1/page-slug/
 * meta_robots/tab-cluster batches. Same "no attachGlobals() bridging"
 * rule applies: every real reader/writer is retargeted in this same
 * batch.
 */
final class PageState
{
    private static ?self $instance = null;

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
     * @var array<string, mixed>
     */
    public array $bodyData = [];

    public string $executionUuid = '';

    /**
     * @var array<string, int>
     */
    public array $metaRobots = [];

    public ?int $authKeyId = null;

    public int $countQueries = 0;

    public float $queriesTime = 0.0;

    /**
     * The instant (microtime(true)) this request began -- former
     * `global $t2`. Still captured at the seam's true top-level scope
     * (include/common.inc.php, before the autoload boundary, for maximum
     * precision) and handed off here early in
     * RequestBootstrap::configure()'s body (right after its own
     * Kernel::boot() call, Legacy Coupling Retirement Phase 8 8a); every
     * other consumer reads it from here.
     */
    public float $requestStart = 0.0;

    /**
     * Accumulated debug-mode query/timing HTML, shown in the page footer
     * -- former `global $debug`, only ever populated when
     * Config::showQueries() is on.
     */
    public string $debugOutput = '';

    /**
     * Batch A5.2i: the CSS id assigned to the page's `<body>` tag, set by
     * whichever of 13 controllers handled the request (each assigns its own
     * fixed literal, e.g. 'theCategoryPage'/'theProfilePage') and read once
     * by PageHeaderRenderer. Ambient like metaRobots/bodyClasses -- no
     * per-caller variation, just "whichever controller ran, set this once."
     */
    public string $bodyId = '';

    /**
     * Nullable (not '') so PageHeaderRenderer's `?? Config::pageBanner()`
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

    /**
     * @var array{days_left: int, dbnow: mixed, auth_key: mixed}|null
     */
    public ?array $notifyApiKeyExpiration = null;

    private function __construct() {}

    /**
     * Called by CommonBootstrap::run() after include/common.inc.php has
     * populated $GLOBALS['page']/$GLOBALS['header_msgs']/
     * $GLOBALS['header_notes'] -- HTTP-path only (index.php), there is no
     * $page concept on the CLI path, so CliBootstrap never calls this.
     * Idempotent: a second call re-points the references but preserves
     * already-accumulated data (the arrays themselves aren't reset).
     */
    public static function attachGlobals(): void
    {
        self::$instance ??= new self();
        $inst = self::$instance;

        $GLOBALS['page'] ??= [];
        $page = &$GLOBALS['page'];
        if (! is_array($page)) {
            $page = [];
        }
        $GLOBALS['header_msgs'] ??= [];
        $GLOBALS['header_notes'] ??= [];

        $inst->errors = self::stringList($page['errors'] ?? $inst->errors);
        $inst->warnings = self::stringList($page['warnings'] ?? $inst->warnings);
        $inst->messages = self::stringList($page['messages'] ?? $inst->messages);
        $inst->infos = self::stringList($page['infos'] ?? $inst->infos);
        $inst->bodyClasses = self::stringList($page['body_classes'] ?? $inst->bodyClasses);
        $inst->bodyData = self::stringKeyedArray($page['body_data'] ?? $inst->bodyData);
        $inst->executionUuid = is_string($page['execution_uuid'] ?? null) ? $page['execution_uuid'] : $inst->executionUuid;
        $inst->metaRobots = self::stringKeyedIntMap($page['meta_robots'] ?? $inst->metaRobots);
        $inst->headerMessages = self::stringList($GLOBALS['header_msgs']);
        $inst->headerNotes = self::stringList($GLOBALS['header_notes']);

        $page['errors'] = &$inst->errors;
        $page['warnings'] = &$inst->warnings;
        $page['messages'] = &$inst->messages;
        $page['infos'] = &$inst->infos;
        $page['body_classes'] = &$inst->bodyClasses;
        $page['body_data'] = &$inst->bodyData;
        $page['execution_uuid'] = &$inst->executionUuid;
        $page['meta_robots'] = &$inst->metaRobots;
        $GLOBALS['header_msgs'] = &$inst->headerMessages;
        $GLOBALS['header_notes'] = &$inst->headerNotes;
    }

    /**
     * Returns the current singleton, creating an empty one if boot hasn't run yet.
     */
    public static function current(): self
    {
        return self::$instance ??= new self();
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
     * PageHeaderRenderer's own `Config::metaRef()` gate both need this
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
     * Called by MysqliDb::query() after every real query -- accumulates
     * both counters together, matching that call site's own original
     * combined increment.
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

    /**
     * Called by UpgradeRunner as a checkpoint before a version-upgrade
     * step, so its own displayed "queries during this step" total isn't
     * inflated by whatever ran earlier in the same request.
     */
    public function resetQueryCounters(): void
    {
        $this->countQueries = 0;
        $this->queriesTime = 0.0;
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

    /**
     * @param array{days_left: int, dbnow: mixed, auth_key: mixed} $data
     */
    public function setNotifyApiKeyExpiration(array $data): void
    {
        $this->notifyApiKeyExpiration = $data;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<string, int>
     */
    private static function stringKeyedIntMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $flag) {
            if (is_string($key) && is_int($flag)) {
                $result[$key] = $flag;
            }
        }

        return $result;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
