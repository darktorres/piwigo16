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
