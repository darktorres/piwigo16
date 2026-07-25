<?php

declare(strict_types=1);

// ActivityLogEntryFormatter calls the real l10n() (unqualified, resolves to
// the global namespace) for its own icon labels -- a real, already-migrated
// function, but one that needs full app bootstrap (LangService/Translator)
// this isolated Unit test deliberately doesn't load. Same "minimal stub to
// load standalone" pattern as tests/Unit/Admin/Upload/UploadServiceTest.php.
if (! function_exists('l10n')) {
    function l10n(string $key, mixed ...$args): string
    {
        return $args === [] ? $key : vsprintf($key, array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $args));
    }
}

// Same reasoning as the l10n() stub above -- format_date() needs the real
// $user['language']/$lang bootstrap this isolated Unit test doesn't load.
// None of this suite's assertions check the formatted date string itself
// (only date/hour splitting and the other passthrough fields), so a plain
// passthrough is enough.
if (! function_exists('format_date')) {
    function format_date(mixed $original, mixed $show = null, mixed $format = null): string
    {
        return is_string($original) ? $original : '';
    }
}

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Core\ActivitySystem;

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

test('core install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(makeActivityRow(), []);

    expect($entry['object_icon'])->toBe('icon-piwigo')
        ->and($entry['object'])->toBe('Core')
        ->and($entry['action_icon'])->toBe('icon-download')
        ->and($entry['action_color'])->toBe('icon-green')
        ->and($entry['action'])->toBe('Install')
        ->and($entry['major_infos'])->toBeFalse();
});

test('core update action is flagged as major_infos', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
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
    $entry = new ActivityLogEntryFormatter()->format(
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
    $entry = new ActivityLogEntryFormatter()->format(
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

test('core config action with a known section', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
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
    $entry = new ActivityLogEntryFormatter()->format(
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

test('theme set_default action', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
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

test('unknown object_id falls through to empty icon/object', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
        makeActivityRow(['object_id' => 999]),
        []
    );

    expect($entry['object_icon'])->toBe('')
        ->and($entry['object'])->toBe('')
        ->and($entry['action_icon'])->toBe('');
});

test('from_version detail overrides the object/action-specific detail', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
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

test('version-only detail is formatted as a version badge', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
        makeActivityRow(['details' => ['version' => '3.1.4']]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'version', 'icon' => 'icon-flow-branch', 'text' => '3.1.4']);
});

test('result-only detail is formatted as an error badge', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
        makeActivityRow(['details' => ['result' => 'failed']]),
        []
    );

    expect($entry['detail'])->toBe(['type' => 'error', 'icon' => 'icon-block', 'text' => 'failed']);
});

test('date and hour are split from occured_on and id/user/username pass through', function (): void {
    $entry = new ActivityLogEntryFormatter()->format(
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
        ->and($entry['hour'])->toBe('09:15:30');
});
