<?php

declare(strict_types=1);

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Admin\Maintenance\Projection\ActivityLogDetail;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\LangTestFactory;

/**
 * ActivityLogEntryFormatter::format() is pure formatting logic given a
 * row (as ActivityRepository::findSystemObjectLogWithUsernames() shapes
 * it) and the batch_manager-style $maint_actions lookup array -- no
 * DB/globals needed.
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
 * ActivityLogEntryFormatter::format() takes a required Lang parameter,
 * but a bare throwaway instance isn't enough here, unlike most other
 * NOCTOR page-renderer Unit tests: format()'s own 'date' key runs through
 * DateHelper::formatDate(), which internally reaches its own private
 * static lang() resolver (a direct container resolve -- no memoized
 * pre-boot fallback, see Lang's own docblock) regardless of what gets
 * passed in here. A real, booted Kernel (see beforeEach()/afterEach() below) is
 * therefore required for every test in this file, so the instance
 * passed to format() might as well be the same container-shared one
 * DateHelper resolves -- with no data loaded, t()/day()/month() all
 * return their raw, untranslated key/index, matching every assertion in
 * this file.
 */
function activityLogEntryFormatterLang(): Lang
{
    return LangTestFactory::get();
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    LangTestFactory::get()->reset();
    Kernel::reset();
});

test('core install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(activityLogEntryFormatterLang(), makeActivityRow(), []);

    expect($entry->objectIcon)
        ->toBe('icon-piwigo')
        ->and($entry->object)
        ->toBe('Core')
        ->and($entry->actionIcon)
        ->toBe('icon-download')
        ->and($entry->actionColor)
        ->toBe('icon-green')
        ->and($entry->action)
        ->toBe('Install')
        ->and($entry->majorInfos)
        ->toBeFalse();
});

test('core update action is flagged as major_infos', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'update',
            ]),
            []
        );

    expect($entry->majorInfos)
        ->toBeTrue()
        ->and($entry->action)
        ->toBe('Update');
});

test('core maintenance action looks up its icon/label from maint_actions', function (): void {
    $maintActions = [
        'user_cache' => [
            'icon' => 'icon-user-1',
            'label' => 'Purge user cache',
        ],
    ];
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'maintenance',
                'details' => [
                    'maintenance_action' => 'user_cache',
                ],
            ]),
            $maintActions
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-user-1',
            text: 'Purge user cache',
        )])
        ->and($entry->detailArrow)
        ->toBeFalse();
});

test('core maintenance action falls back to the raw action name when unknown to maint_actions', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'maintenance',
                'details' => [
                    'maintenance_action' => 'some_future_action',
                ],
            ]),
            [] // empty_lounge's own drift bug fix: this action must resolve even
            // when $maintActions doesn't have the entry (e.g. a plugin-added
            // maintenance action not in the built-in list).
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-cone',
            text: 'some_future_action',
        )]);
});

test('core maintenance action with a non-string/non-int maintenance_action falls back to an empty lookup key', function (): void {
    // A leftover legacy bool/array payload can't be used as an array key at
    // all, so the real code substitutes '' -- verified by making that ''
    // itself a hit in $maintActions (a mutated fallback string would miss
    // this lookup and fall through to the generic icon-cone/raw-$action_detail
    // default instead).
    $maintActions = [
        '' => [
            'icon' => 'icon-fallback-key',
            'label' => 'Fallback key label',
        ],
    ];
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'maintenance',
                'details' => [
                    'maintenance_action' => true,
                ],
            ]),
            $maintActions
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-fallback-key',
            text: 'Fallback key label',
        )]);
});

test('core config action with a known section', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'config',
                'details' => [
                    'config_section' => 'watermark',
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-file-image',
            text: 'Watermark',
        )]);
});

test('plugin delete action reports db and filesystem version details', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'delete',
                'details' => [
                    'plugin_id' => 'my_plugin',
                    'db_version' => '1.2.3',
                    'fs_version' => '1.2.4',
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('My Plugin')
        ->and($entry->actionIcon)
        ->toBe('icon-trash-1')
        ->and($entry->actionColor)
        ->toBe('icon-red')
        // db_version and fs_version each push their own item, in order.
        ->and($entry->detailItems)
        ->toEqual([
            new ActivityLogDetail(
                icon: 'icon-flow-branch',
                text: 'database : 1.2.3',
            ),
            new ActivityLogDetail(
                icon: 'icon-flow-branch',
                text: 'filesystem : 1.2.4',
            ),
        ])
        ->and($entry->detailArrow)
        ->toBeFalse();
});

test('plugin_id present but non-string is left unused, not passed to str_replace', function (): void {
    // isset() is true but is_string() is false here -- real code requires
    // BOTH (an OR would instead try str_replace() on a non-string $subject,
    // which throws a TypeError under strict_types=1).
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'details' => [
                    'plugin_id' => 123,
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('Plugin');
});

test('db_version present but non-string is left out of the delete detail', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'delete',
                'details' => [
                    'plugin_id' => 'x',
                    'db_version' => 123,
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toBe([]);
});

test('fs_version present but non-string is left out of the delete detail', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'delete',
                'details' => [
                    'plugin_id' => 'x',
                    'fs_version' => 123,
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toBe([]);
});

test('theme_id present but non-string is left unused, not passed to str_replace', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'details' => [
                    'theme_id' => 123,
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('Theme');
});

test('theme_id with both an underscore and a hyphen gets both replaced with spaces', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'details' => [
                    'theme_id' => 'my_theme-name',
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('My Theme Name');
});

test('theme set_default action', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'set_default',
                'details' => [
                    'theme_id' => 'my-theme',
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('My Theme')
        ->and($entry->actionIcon)
        ->toBe('icon-star')
        ->and($entry->action)
        ->toBe('Set as default');
});

test('unknown object_id falls through to empty icon/object/color and the default empty detail', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => 999,
            ]),
            []
        );

    expect($entry->objectIcon)
        ->toBe('')
        ->and($entry->object)
        ->toBe('')
        ->and($entry->actionIcon)
        ->toBe('')
        ->and($entry->actionColor)
        ->toBe('')
        ->and($entry->detailItems)
        ->toBe([]);
});

test('from_version detail overrides the object/action-specific detail', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'update',
                'details' => [
                    'plugin_id' => 'x',
                    'from_version' => '1.0',
                    'to_version' => '2.0',
                ],
            ]),
            []
        );

    expect($entry->detailArrow)
        ->toBeTrue()
        ->and($entry->detailItems)
        ->toEqual([
            new ActivityLogDetail(
                icon: 'icon-flow-branch',
                text: '1.0',
            ),
            new ActivityLogDetail(
                icon: 'icon-flow-branch',
                text: '2.0',
            ),
        ]);
});

test('from_version detail with no to_version falls back to the result value', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'details' => [
                    'from_version' => '1.0',
                    'result' => 'failed',
                ],
            ]),
            []
        );

    expect($entry->detailItems[1])->toEqual(new ActivityLogDetail(
        icon: 'icon-block',
        text: 'failed',
    ));
});

test('from_version detail with neither to_version nor result falls back to an empty text', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'details' => [
                    'from_version' => '1.0',
                ],
            ]),
            []
        );

    expect($entry->detailItems[1])->toEqual(new ActivityLogDetail(
        icon: 'icon-block',
        text: '',
    ));
});

test('version-only detail is formatted as a version badge', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'details' => [
                    'version' => '3.1.4',
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-flow-branch',
            text: '3.1.4',
        )])
        ->and($entry->detailArrow)
        ->toBeFalse();
});

test('result-only detail is formatted as an error badge', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'details' => [
                    'result' => 'failed',
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-block',
            text: 'failed',
        )])
        ->and($entry->detailArrow)
        ->toBeFalse();
});

test('core config action with an unknown section falls back to the raw section name', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'config',
                'details' => [
                    'config_section' => 'totally-unknown-section',
                ],
            ]),
            []
        );

    expect($entry->detailItems)
        ->toEqual([new ActivityLogDetail(
            icon: 'icon-cog-alt',
            text: 'totally-unknown-section',
        )]);
});

test('core autoupdate action is flagged as major_infos with the blue update icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'autoupdate',
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-arrows-cw')
        ->and($entry->actionColor)
        ->toBe('icon-blue')
        ->and($entry->action)
        ->toBe('Auto-update')
        ->and($entry->majorInfos)
        ->toBeTrue();
});

test('core unknown action falls back to the default yellow download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'action' => 'some-future-core-action',
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-download')
        ->and($entry->actionColor)
        ->toBe('icon-yellow')
        // Core's default arm never overwrites $action -- it stays the raw
        // action string from the row, distinct from every named arm above
        // which replaces it with a translated label.
        ->and($entry->action)
        ->toBe('some-future-core-action');
});

test('plugin install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'install',
                'details' => [
                    'plugin_id' => 'plugin-one',
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('Plugin One')
        ->and($entry->actionIcon)
        ->toBe('icon-download')
        ->and($entry->actionColor)
        ->toBe('icon-green')
        ->and($entry->action)
        ->toBe('Install');
});

test('plugin activate action gets the green check icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'activate',
                'details' => [
                    'plugin_id' => 'plugin-two',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-check')
        ->and($entry->actionColor)
        ->toBe('icon-green')
        ->and($entry->action)
        ->toBe('Activate');
});

test('plugin deactivate action gets the purple block icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'deactivate',
                'details' => [
                    'plugin_id' => 'plugin-three',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-block')
        ->and($entry->actionColor)
        ->toBe('icon-purple')
        ->and($entry->action)
        ->toBe('Deactivate');
});

test('plugin uninstall action gets the red trash icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'uninstall',
                'details' => [
                    'plugin_id' => 'plugin-four',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-trash-1')
        ->and($entry->actionColor)
        ->toBe('icon-red')
        ->and($entry->action)
        ->toBe('Uninstall');
});

test('plugin restore action gets the blue back-in-time icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'restore',
                'details' => [
                    'plugin_id' => 'plugin-five',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-back-in-time')
        ->and($entry->actionColor)
        ->toBe('icon-blue')
        ->and($entry->action)
        ->toBe('Restore');
});

test('plugin autoupdate action gets the blue update icon, not flagged major_infos', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'autoupdate',
                'details' => [
                    'plugin_id' => 'plugin-six',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-arrows-cw')
        ->and($entry->actionColor)
        ->toBe('icon-blue')
        ->and($entry->action)
        ->toBe('Auto-update')
        // Unlike Core's own 'autoupdate'/'update' arms, the Plugin arm never
        // sets major_infos.
        ->and($entry->majorInfos)
        ->toBeFalse();
});

test('plugin unknown action falls back to the default yellow puzzle icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Plugin,
                'action' => 'some-future-plugin-action',
                'details' => [
                    'plugin_id' => 'plugin-seven',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-puzzle')
        ->and($entry->actionColor)
        ->toBe('icon-yellow')
        ->and($entry->action)
        ->toBe('some-future-plugin-action');
});

test('theme install action gets the green download icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'install',
                'details' => [
                    'theme_id' => 'theme-one',
                ],
            ]),
            []
        );

    expect($entry->object)
        ->toBe('Theme One')
        ->and($entry->actionIcon)
        ->toBe('icon-download')
        ->and($entry->actionColor)
        ->toBe('icon-green')
        ->and($entry->action)
        ->toBe('Install');
});

test('theme deactivate action gets the purple block icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'deactivate',
                'details' => [
                    'theme_id' => 'theme-two',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-block')
        ->and($entry->actionColor)
        ->toBe('icon-purple')
        ->and($entry->action)
        ->toBe('Deactivate');
});

test('theme delete action gets the red trash icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'delete',
                'details' => [
                    'theme_id' => 'theme-three',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-trash-1')
        ->and($entry->actionColor)
        ->toBe('icon-red')
        ->and($entry->action)
        ->toBe('Delete');
});

test('theme update action gets the blue update icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'update',
                'details' => [
                    'theme_id' => 'theme-four',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-arrows-cw')
        ->and($entry->actionColor)
        ->toBe('icon-blue')
        ->and($entry->action)
        ->toBe('Update');
});

test('theme unknown action falls back to the default yellow brush icon', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'object_id' => ActivitySystem::Theme,
                'action' => 'some-future-theme-action',
                'details' => [
                    'theme_id' => 'theme-five',
                ],
            ]),
            []
        );

    expect($entry->actionIcon)
        ->toBe('icon-brush')
        ->and($entry->actionColor)
        ->toBe('icon-yellow')
        ->and($entry->action)
        ->toBe('some-future-theme-action');
});

test('date and hour are split from occured_on and id/user/username pass through', function (): void {
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            makeActivityRow([
                'activity_id' => 7,
                'performed_by' => 3,
                'username' => 'someone',
                'occured_on' => '2026-08-01 09:15:30',
            ]),
            []
        );

    expect($entry->id)
        ->toBe(7)
        ->and($entry->userId)
        ->toBe(3)
        ->and($entry->username)
        ->toBe('someone')
        ->and($entry->initial)
        ->toBe('S')
        // The raw halves, not rendered ones: maintenance_sys.latte runs
        // $date through the `format_date` filter, which is
        // DateHelper::formatDate() itself. Asserting the raw value here is
        // what makes that move visible -- the previous assertion compared
        // against formatDate() on both sides and so would have passed
        // whichever side did the formatting.
        ->and($entry->date)
        ->toBe('2026-08-01')
        ->and($entry->hour)
        ->toBe('09:15:30')
        ->and(DateHelper::formatDate($entry->date))
        ->toBe('Saturday, August 1, 2026');
});

test('a null username (a deleted user) gets an empty initial, not a crash', function (): void {
    // makeActivityRow()'s own `??` merge can't pass an explicit null through
    // (null ?? default resolves to the default), so this constructs the row
    // directly.
    $entry = new ActivityLogEntryFormatter()
        ->format(
            activityLogEntryFormatterLang(),
            new SystemActivityLogEntry(
                activityId: 42,
                performedBy: 1,
                objectId: ActivitySystem::Core,
                action: 'install',
                occuredOn: '2026-08-01 12:34:56',
                details: null,
                username: null,
            ),
            []
        );

    expect($entry->username)
        ->toBeNull()
        ->and($entry->initial)
        ->toBe('');
});
