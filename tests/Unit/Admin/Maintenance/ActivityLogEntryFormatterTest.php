<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;

/**
 * ActivityLogEntryFormatter::format() -- extracted from
 * admin/maintenance_sys.php's own per-row body during the P23 batch 6h
 * port. Pure formatting logic given a row (as
 * ActivityRepository::findSystemObjectLogWithUsernames() shapes it) and
 * the batch_manager-style $maint_actions lookup array, no DB/globals
 * needed.
 */
/**
 * @param array{activity_id?: int, performed_by?: ?int, object_id?: int,
 *   action?: string, occured_on?: string, details?: ?array<string, mixed>,
 *   username?: ?string} $overrides same column-name keys the real DB row
 *   uses -- unlike SystemActivityLogEntry::fromRow() (which decodes
 *   'details' from a JSON *string*, matching the real repository's own row
 *   shape), 'details' is passed here as a real array directly, since every
 *   test call site already builds one.
 */
function makeActivityRow(array $overrides = []): SystemActivityLogEntry
{
    return new SystemActivityLogEntry(
        activityId: $overrides['activity_id'] ?? 42,
        performedBy: $overrides['performed_by'] ?? 1,
        objectId: $overrides['object_id'] ?? ActivitySystem::Core,
        action: $overrides['action'] ?? 'install',
        occuredOn: $overrides['occured_on'] ?? '2026-08-01 12:34:56',
        details: $overrides['details'] ?? null,
        username: $overrides['username'] ?? 'fixture_admin',
    );
}

/**
 * ActivityLogEntryFormatter::format() gained a required Lang parameter
 * (singleton/service-locator elimination campaign, Phase 8) -- but a
 * bare throwaway instance isn't enough here, unlike most other NOCTOR
 * page-renderer Unit tests: format()'s own 'date' key runs through
 * DateHelper::formatDate(), which internally reaches Lang::current()
 * (the deprecated container-resolve shim -- no memoized pre-boot
 * fallback, see Lang's own docblock) regardless of what gets passed in
 * here. A real, booted Kernel (see beforeEach()/afterEach() below) is
 * therefore required for every test in this file, so the instance
 * passed to format() might as well be the same container-shared one
 * DateHelper resolves -- with no data loaded, t()/day()/month() all
 * return their raw, untranslated key/index, matching every assertion in
 * this file.
 */
function activityLogEntryFormatterLang(): Lang
{
    return Lang::current();
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    Lang::current()->reset();
    Kernel::reset();
});

test('core install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), makeActivityRow(), []);

    expect($entry['object_icon'])->toBe('icon-piwigo')
        ->and($entry['object'])->toBe('Core')
        ->and($entry['action_icon'])->toBe('icon-download')
        ->and($entry['action_color'])->toBe('icon-green')
        ->and($entry['action'])->toBe('Install')
        ->and($entry['major_infos'])->toBeFalse();
});

test('core update action is flagged as major_infos', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['action' => 'update']),
        []
    );

    expect($entry['major_infos'])->toBeTrue()
        ->and($entry['action'])->toBe('Update');
});

test('core maintenance action looks up its icon/label from maint_actions', function (): void {
    $maintActions = [
        'user_cache' => ['icon' => 'icon-user-1', 'label' => 'Purge user cache'],
    ];
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'action' => 'maintenance',
            'details' => ['maintenance_action' => 'user_cache'],
        ]),
        $maintActions
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['type'])->toBe('maintenance_action')
        ->and($detail['icon'])->toBe('icon-user-1')
        ->and($detail['text'])->toBe('Purge user cache');
});

test('core maintenance action falls back to the raw action name when unknown to maint_actions', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'action' => 'maintenance',
            'details' => ['maintenance_action' => 'some_future_action'],
        ]),
        [] // empty_lounge's own drift bug fix: this action must resolve even
           // when $maintActions doesn't have the entry (e.g. a plugin-added
           // maintenance action not in the built-in list).
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['icon'])->toBe('icon-cone')
        ->and($detail['text'])->toBe('some_future_action');
});

test('core maintenance action with a non-string/non-int maintenance_action falls back to an empty lookup key', function (): void {
    // A leftover legacy bool/array payload can't be used as an array key at
    // all, so the real code substitutes '' -- verified by making that ''
    // itself a hit in $maintActions (a mutated fallback string would miss
    // this lookup and fall through to the generic icon-cone/raw-$action_detail
    // default instead).
    $maintActions = [
        '' => ['icon' => 'icon-fallback-key', 'label' => 'Fallback key label'],
    ];
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'action' => 'maintenance',
            'details' => ['maintenance_action' => true],
        ]),
        $maintActions
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['icon'])->toBe('icon-fallback-key')
        ->and($detail['text'])->toBe('Fallback key label');
});

test('core config action with a known section', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'action' => 'config',
            'details' => ['config_section' => 'watermark'],
        ]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['type'])->toBe('config_section')
        ->and($detail[0])->toBe(['icon' => 'icon-file-image', 'text' => 'Watermark']);
});

test('plugin delete action reports db and filesystem version details', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'object_id' => ActivitySystem::Plugin,
            'action' => 'delete',
            'details' => ['plugin_id' => 'my_plugin', 'db_version' => '1.2.3', 'fs_version' => '1.2.4'],
        ]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($entry['object'])->toBe('My Plugin')
        ->and($entry['action_icon'])->toBe('icon-trash-1')
        ->and($entry['action_color'])->toBe('icon-red')
        ->and($detail['type'])->toBe('db_fs_version')
        // both db_version and fs_version push onto the same $detail array in
        // order -- last one set (filesystem) wins for indices 0, both remain
        // reachable via the array's own numeric keys.
        ->and($detail[0])->toBe(['icon' => 'icon-flow-branch', 'text' => 'database : 1.2.3'])
        ->and($detail[1])->toBe(['icon' => 'icon-flow-branch', 'text' => 'filesystem : 1.2.4']);
});

test('plugin_id present but non-string is left unused, not passed to str_replace', function (): void {
    // isset() is true but is_string() is false here -- real code requires
    // BOTH (an OR would instead try str_replace() on a non-string $subject,
    // which throws a TypeError under strict_types=1).
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'details' => ['plugin_id' => 123]]),
        []
    );

    expect($entry['object'])->toBe('Plugin');
});

test('db_version present but non-string is left out of the delete detail', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'object_id' => ActivitySystem::Plugin,
            'action' => 'delete',
            'details' => ['plugin_id' => 'x', 'db_version' => 123],
        ]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'empty']);
});

test('fs_version present but non-string is left out of the delete detail', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'object_id' => ActivitySystem::Plugin,
            'action' => 'delete',
            'details' => ['plugin_id' => 'x', 'fs_version' => 123],
        ]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'empty']);
});

test('theme_id present but non-string is left unused, not passed to str_replace', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'details' => ['theme_id' => 123]]),
        []
    );

    expect($entry['object'])->toBe('Theme');
});

test('theme_id with both an underscore and a hyphen gets both replaced with spaces', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'details' => ['theme_id' => 'my_theme-name']]),
        []
    );

    expect($entry['object'])->toBe('My Theme Name');
});

test('theme set_default action', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'object_id' => ActivitySystem::Theme,
            'action' => 'set_default',
            'details' => ['theme_id' => 'my-theme'],
        ]),
        []
    );

    expect($entry['object'])->toBe('My Theme')
        ->and($entry['action_icon'])->toBe('icon-star')
        ->and($entry['action'])->toBe('Set as default');
});

test('unknown object_id falls through to empty icon/object/color and the default empty detail', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => 999]),
        []
    );

    expect($entry['object_icon'])->toBe('')
        ->and($entry['object'])->toBe('')
        ->and($entry['action_icon'])->toBe('')
        ->and($entry['action_color'])->toBe('')
        ->and($entry['detail'])->toBe(['type' => 'empty']);
});

test('from_version detail overrides the object/action-specific detail', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'object_id' => ActivitySystem::Plugin,
            'action' => 'update',
            'details' => ['plugin_id' => 'x', 'from_version' => '1.0', 'to_version' => '2.0'],
        ]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['type'])->toBe('from_to')
        ->and($detail[0])->toBe(['icon' => 'icon-flow-branch', 'text' => '1.0'])
        ->and($detail[1])->toBe(['icon' => 'icon-flow-branch', 'text' => '2.0']);
});

test('from_version detail with no to_version falls back to the result value', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['details' => ['from_version' => '1.0', 'result' => 'failed']]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail[1])->toBe(['icon' => 'icon-block', 'text' => 'failed']);
});

test('from_version detail with neither to_version nor result falls back to an empty text', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['details' => ['from_version' => '1.0']]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail[1])->toBe(['icon' => 'icon-block', 'text' => '']);
});

test('version-only detail is formatted as a version badge', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['details' => ['version' => '3.1.4']]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'version', 'icon' => 'icon-flow-branch', 'text' => '3.1.4']);
});

test('result-only detail is formatted as an error badge', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['details' => ['result' => 'failed']]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'error', 'icon' => 'icon-block', 'text' => 'failed']);
});

test('core config action with an unknown section falls back to the raw section name', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'action' => 'config',
            'details' => ['config_section' => 'totally-unknown-section'],
        ]),
        []
    );

    $detail = is_array($entry['detail']) ? $entry['detail'] : [];
    expect($detail['type'])->toBe('config_section')
        ->and($detail[0])->toBe(['icon' => 'icon-cog-alt', 'text' => 'totally-unknown-section']);
});

test('core autoupdate action is flagged as major_infos with the blue update icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['action' => 'autoupdate']),
        []
    );

    expect($entry['action_icon'])->toBe('icon-arrows-cw')
        ->and($entry['action_color'])->toBe('icon-blue')
        ->and($entry['action'])->toBe('Auto-update')
        ->and($entry['major_infos'])->toBeTrue();
});

test('core unknown action falls back to the default yellow download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['action' => 'some-future-core-action']),
        []
    );

    expect($entry['action_icon'])->toBe('icon-download')
        ->and($entry['action_color'])->toBe('icon-yellow')
        // Core's default arm never overwrites $action -- it stays the raw
        // action string from the row, distinct from every named arm above
        // which replaces it with a translated label.
        ->and($entry['action'])->toBe('some-future-core-action');
});

test('plugin install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'install', 'details' => ['plugin_id' => 'plugin-one']]),
        []
    );

    expect($entry['object'])->toBe('Plugin One')
        ->and($entry['action_icon'])->toBe('icon-download')
        ->and($entry['action_color'])->toBe('icon-green')
        ->and($entry['action'])->toBe('Install');
});

test('plugin activate action gets the green check icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'activate', 'details' => ['plugin_id' => 'plugin-two']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-check')
        ->and($entry['action_color'])->toBe('icon-green')
        ->and($entry['action'])->toBe('Activate');
});

test('plugin deactivate action gets the purple block icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'deactivate', 'details' => ['plugin_id' => 'plugin-three']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-block')
        ->and($entry['action_color'])->toBe('icon-purple')
        ->and($entry['action'])->toBe('Deactivate');
});

test('plugin uninstall action gets the red trash icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'uninstall', 'details' => ['plugin_id' => 'plugin-four']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-trash-1')
        ->and($entry['action_color'])->toBe('icon-red')
        ->and($entry['action'])->toBe('Uninstall');
});

test('plugin restore action gets the blue back-in-time icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'restore', 'details' => ['plugin_id' => 'plugin-five']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-back-in-time')
        ->and($entry['action_color'])->toBe('icon-blue')
        ->and($entry['action'])->toBe('Restore');
});

test('plugin autoupdate action gets the blue update icon, not flagged major_infos', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'autoupdate', 'details' => ['plugin_id' => 'plugin-six']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-arrows-cw')
        ->and($entry['action_color'])->toBe('icon-blue')
        ->and($entry['action'])->toBe('Auto-update')
        // Unlike Core's own 'autoupdate'/'update' arms, the Plugin arm never
        // sets major_infos.
        ->and($entry['major_infos'])->toBeFalse();
});

test('plugin unknown action falls back to the default yellow puzzle icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Plugin, 'action' => 'some-future-plugin-action', 'details' => ['plugin_id' => 'plugin-seven']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-puzzle')
        ->and($entry['action_color'])->toBe('icon-yellow')
        ->and($entry['action'])->toBe('some-future-plugin-action');
});

test('theme install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'action' => 'install', 'details' => ['theme_id' => 'theme-one']]),
        []
    );

    expect($entry['object'])->toBe('Theme One')
        ->and($entry['action_icon'])->toBe('icon-download')
        ->and($entry['action_color'])->toBe('icon-green')
        ->and($entry['action'])->toBe('Install');
});

test('theme deactivate action gets the purple block icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'action' => 'deactivate', 'details' => ['theme_id' => 'theme-two']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-block')
        ->and($entry['action_color'])->toBe('icon-purple')
        ->and($entry['action'])->toBe('Deactivate');
});

test('theme delete action gets the red trash icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'action' => 'delete', 'details' => ['theme_id' => 'theme-three']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-trash-1')
        ->and($entry['action_color'])->toBe('icon-red')
        ->and($entry['action'])->toBe('Delete');
});

test('theme update action gets the blue update icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'action' => 'update', 'details' => ['theme_id' => 'theme-four']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-arrows-cw')
        ->and($entry['action_color'])->toBe('icon-blue')
        ->and($entry['action'])->toBe('Update');
});

test('theme unknown action falls back to the default yellow brush icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow(['object_id' => ActivitySystem::Theme, 'action' => 'some-future-theme-action', 'details' => ['theme_id' => 'theme-five']]),
        []
    );

    expect($entry['action_icon'])->toBe('icon-brush')
        ->and($entry['action_color'])->toBe('icon-yellow')
        ->and($entry['action'])->toBe('some-future-theme-action');
});

test('date and hour are split from occured_on and id/user/username pass through', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(activityLogEntryFormatterLang(), 
        makeActivityRow([
            'activity_id' => 7,
            'performed_by' => 3,
            'username' => 'someone',
            'occured_on' => '2026-08-01 09:15:30',
        ]),
        []
    );

    expect($entry['id'])->toBe(7)
        ->and($entry['user_id'])->toBe(3)
        ->and($entry['username'])->toBe('someone')
        ->and($entry['date'])->toBe(\Piwigo\Core\DateHelper::formatDate('2026-08-01'))
        ->and($entry['hour'])->toBe('09:15:30');
});
