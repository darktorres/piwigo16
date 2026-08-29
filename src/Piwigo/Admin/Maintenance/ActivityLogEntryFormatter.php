<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Admin\Maintenance\Projection\ActivityLogDetail;
use Piwigo\Admin\Maintenance\Projection\ActivityLogRow;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Lang;

/**
 * Formats one Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames() row into the icon/color/label/detail
 * shape the "sys" tab's server-rendered activity log
 * ({@see \Piwigo\Admin\MaintenanceSysPageRenderer}) needs. Pure data
 * transformation given a row, with zero DB/IO side effects, so it's
 * Unit-testable directly.
 */
final class ActivityLogEntryFormatter
{
    /**
     * $details/the returned 'detailItems' are genuinely polymorphic by
     * design: $row->details is an entity-agnostic activity-log payload
     * (same rationale as Audit\AuditService's own $before/$after) that can
     * resolve to zero, one, or two detail items depending on
     * $row->objectId/$row->action (config_section/maintenance_action/
     * from_to/version/error/empty) -- normalized here into a flat
     * `list<array{icon: string, text: string}>` (0-2 items) plus a
     * `detailArrow` flag (only the from_to case renders an arrow between
     * its exactly-2 items), since the template rendering this needs a
     * uniform shape, not the type-discriminated one a JS switch statement
     * used to branch on. Normalized here means normalized into
     * {@see ActivityLogDetail}: the polymorphism is in what the payload
     * *says*, not in the shape the template reads.
     *
     * @param array<string, array{icon: string, label: string}> $maintActions
     *   matches MaintenanceSysPageRenderer::render()'s own already-precise
     *   param shape (this method's only real caller passes it straight through)
     */
    public function format(Lang $lang, SystemActivityLogEntry $row, array $maintActions): ActivityLogRow
    {
        $major_infos = false;
        $object = '';
        $object_icon = '';
        $action_icon = '';
        $action_color = '';
        $action = $row->action;
        $details = $row->details ?? [];
        /** @var list<ActivityLogDetail> $detailItems */
        $detailItems = [];
        $detailArrow = false;

        // For each categories (Core, Plugin and Theme) we need to format theirs actions
        switch ($row->objectId) {
            case ActivitySystem::Core:
                $object_icon = 'icon-piwigo';
                $object = $lang->t('Core');

                switch ($row->action) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = $lang->t('Install');
                        break;

                    case 'config':
                        $action_icon = 'icon-cog-alt';
                        $action_color = 'icon-yellow';
                        $action = $lang->t('Configuration');
                        // for config we need to specific format details
                        if (isset($details['config_section'])) {
                            // $c_icon/$c_text's '' initial values are dead: every
                            // arm of the switch below (including its own
                            // default:) unconditionally reassigns both, so
                            // these two never survive to be read -- no test
                            // can observe a mutation to either literal.
                            $c_icon = '';
                            $c_text = '';
                            switch ($details['config_section']) {
                                case 'main':
                                    $c_icon = 'icon-cog';
                                    $c_text = $lang->t('General');
                                    break;

                                case 'watermark':
                                    $c_icon = 'icon-file-image';
                                    $c_text = $lang->t('Watermark');
                                    break;

                                case 'sizes':
                                    $c_icon = 'icon-zoom-square';
                                    $c_text = $lang->t('Photo sizes');
                                    // sizes have 2 params always Photo sizes and sometimes config_action
                                    if (isset($details['config_action']) && $details['config_action'] === 'restore_settings') {
                                        $detailItems[] = new ActivityLogDetail(
                                            icon: 'icon-back-in-time',
                                            text: $lang->t('Set as default'),
                                        );
                                    }
                                    break;

                                case 'comments':
                                    $c_icon = 'icon-chat';
                                    $c_text = $lang->t('Comments');
                                    break;

                                case 'display':
                                    $c_icon = 'icon-television';
                                    $c_text = $lang->t('Display');
                                    break;

                                default:
                                    $c_icon = 'icon-cog-alt';
                                    // An unrecognised section name renders
                                    // as itself; `details` is an
                                    // entity-agnostic payload, so that name
                                    // is only a string by convention.
                                    $c_text = self::text($details['config_section']);
                                    break;
                            }

                            $detailItems[] = new ActivityLogDetail(
                                icon: $c_icon,
                                text: $c_text,
                            );
                        }
                        break;

                    case 'maintenance':
                        $action_icon = 'icon-cone';
                        $action_color = 'icon-yellow';
                        $action = $lang->t('Maintenance');
                        // for maintenance we need to specific format details
                        if (isset($details['maintenance_action'])) {
                            $action_detail = $details['maintenance_action'];
                            // Array keys are only ever int|string; anything else
                            // (e.g. leftover legacy bool/array payloads) falls back
                            // to an empty-string key, which simply misses the lookup.
                            $action_detail_key = is_string($action_detail) || is_int($action_detail) ? $action_detail : '';
                            $maint_action_entry = $maintActions[$action_detail_key] ?? null;
                            $detailItems = [new ActivityLogDetail(
                                icon: $maint_action_entry['icon'] ?? 'icon-cone',
                                text: $maint_action_entry['label'] ?? (is_string($action_detail) ? $action_detail : ''),
                            )];
                        }
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Update');
                        $major_infos = true;
                        break;

                    case 'autoupdate':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Auto-update');
                        $major_infos = true;
                        break;

                    default:
                        $action_icon = 'icon-download';
                        $action_color = 'icon-yellow';
                        break;
                }
                break;

            case ActivitySystem::Plugin:
                $object_icon = 'icon-puzzle';
                $object = 'plugin';
                if (isset($details['plugin_id']) && is_string($details['plugin_id'])) {
                    $object = str_replace(['_', '-'], ' ', $details['plugin_id']);
                }
                switch ($row->action) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = $lang->t('Install');
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Update');
                        break;

                    case 'activate':
                        $action_icon = 'icon-check';
                        $action_color = 'icon-green';
                        $action = $lang->t('Activate');
                        break;

                    case 'deactivate':
                        $action_icon = 'icon-block';
                        $action_color = 'icon-purple';
                        $action = $lang->t('Deactivate');
                        break;

                    case 'uninstall':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = $lang->t('Uninstall');
                        break;

                    case 'restore':
                        $action_icon = 'icon-back-in-time';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Restore');
                        break;

                    case 'delete':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = $lang->t('Delete');
                        // for delete we need to specific format details
                        if (isset($details['db_version']) && is_string($details['db_version'])) {
                            $detailItems[] = new ActivityLogDetail(
                                icon: 'icon-flow-branch',
                                text: 'database : ' . $details['db_version'],
                            );
                        }
                        if (isset($details['fs_version']) && is_string($details['fs_version'])) {
                            $detailItems[] = new ActivityLogDetail(
                                icon: 'icon-flow-branch',
                                text: 'filesystem : ' . $details['fs_version'],
                            );
                        }
                        break;

                    case 'autoupdate':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Auto-update');
                        break;

                    default:
                        $action_icon = 'icon-puzzle';
                        $action_color = 'icon-yellow';
                        break;
                }
                break;

            case ActivitySystem::Theme:
                $object_icon = 'icon-brush';
                $object = 'theme';
                if (isset($details['theme_id']) && is_string($details['theme_id'])) {
                    $object = str_replace(['_', '-'], ' ', $details['theme_id']);
                }

                switch ($row->action) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = $lang->t('Install');
                        break;

                    case 'activate':
                        $action_icon = 'icon-check';
                        $action_color = 'icon-green';
                        $action = $lang->t('Activate');
                        break;

                    case 'deactivate':
                        $action_icon = 'icon-block';
                        $action_color = 'icon-purple';
                        $action = $lang->t('Deactivate');
                        break;

                    case 'delete':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = $lang->t('Delete');
                        break;

                    case 'set_default':
                        $action_icon = 'icon-star';
                        $action_color = 'icon-yellow';
                        $action = $lang->t('Set as default');
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = $lang->t('Update');
                        break;

                    default:
                        $action_icon = 'icon-brush';
                        $action_color = 'icon-yellow';
                        break;
                }
                break;

            default:
                break;
        }

        // For each lines we need to format theirs details (general details)
        if (isset($details['from_version'])) {
            $detailItems = [
                new ActivityLogDetail(
                    icon: 'icon-flow-branch',
                    text: self::text($details['from_version']),
                ),
                new ActivityLogDetail(
                    icon: isset($details['to_version']) ? 'icon-flow-branch' : 'icon-block',
                    text: self::text($details['to_version'] ?? ($details['result'] ?? '')),
                ),
            ];
            $detailArrow = true;
        } elseif (isset($details['version'])) {
            $detailItems = [new ActivityLogDetail(
                icon: 'icon-flow-branch',
                text: self::text($details['version']),
            )];
        } elseif (isset($details['result'])) {
            $detailItems = [new ActivityLogDetail(
                icon: 'icon-block',
                text: self::text($details['result']),
            )];
        }

        // occuredOn is always 'Y-m-d H:i:s' (SqlDateTime), but a row that
        // reached SystemActivityLogEntry::fromRow() with no usable value at
        // all carries '' -- explode() then yields a single element, so take
        // the halves positionally rather than by destructuring a pair.
        $parts = explode(' ', $row->occuredOn);
        $date = $parts[0];
        $hour = $parts[1] ?? '';

        return new ActivityLogRow(
            id: $row->activityId,
            majorInfos: $major_infos,
            objectIcon: $object_icon,
            object: ucwords($object),
            actionIcon: $action_icon,
            actionColor: $action_color,
            action: $action,
            // 0 exactly when the query said 'System' -- see ActivityLogRow's
            // own docblock for why the template never divides by it.
            userId: $row->performedBy ?? 0,
            username: $row->username,
            initial: mb_strtoupper(mb_substr($row->username ?? '', 0, 1)),
            date: $date,
            hour: $hour,
            detailItems: $detailItems,
            detailArrow: $detailArrow,
        );
    }

    /**
     * `details` is an entity-agnostic payload, so a version/result value
     * that should be a string can be anything a caller once wrote. Rendering
     * one is a string context either way; this makes that explicit instead of
     * leaving it to an implicit cast the template would have to absorb.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
