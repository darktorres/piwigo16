<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Activity\Projection\SystemActivityLogEntry;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Lang;

/**
 * Formats one Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames() row into the icon/color/label/detail
 * shape the "sys" tab's `pwg.activity_sys.getList` ajax response needs.
 * Pure data transformation given a row, with zero DB/IO side effects, so
 * it's Unit-testable directly.
 */
final class ActivityLogEntryFormatter
{
    /**
     * $details/the returned 'detail' key are genuinely polymorphic by
     * design: $row->details is an entity-agnostic activity-log payload
     * (same rationale as Audit\AuditService's own $before/$after), and
     * 'detail' itself takes one of several different shapes below
     * depending on $row->objectId/$row->action (config_section/
     * maintenance_action/from_to/version/error/empty).
     *
     * @param array<string, array{icon: string, label: string}> $maintActions
     *   matches MaintenanceSysPageRenderer::render()'s own already-precise
     *   param shape (this method's only real caller passes it straight through)
     *
     * @return array<string, mixed>
     */
    public function format(Lang $lang, SystemActivityLogEntry $row, array $maintActions): array
    {
        $major_infos = false;
        $object = '';
        $object_icon = '';
        $action_icon = '';
        $action_color = '';
        $action = $row->action;
        $details = $row->details ?? [];
        $detail = [
            'type' => 'empty',
        ];

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
                            // these two never survive to be read -- confirmed
                            // while investigating a mutation-testing gap, no
                            // test can observe a mutation to either literal.
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
                                        $detail[] = [
                                            'icon' => 'icon-back-in-time',
                                            'text' => $lang->t('Set as default'),
                                        ];
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
                                    $c_text = $details['config_section'];
                                    break;
                            }

                            $detail['type'] = 'config_section';
                            $detail[] = [
                                'icon' => $c_icon,
                                'text' => $c_text,
                            ];
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
                            $detail = [
                                'type' => 'maintenance_action',
                                'icon' => $maint_action_entry['icon'] ?? 'icon-cone',
                                'text' => $maint_action_entry['label'] ?? $action_detail,
                            ];
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
                            $detail['type'] = 'db_fs_version';
                            $detail[] = [
                                'icon' => 'icon-flow-branch',
                                'text' => 'database : ' . $details['db_version'],
                            ];
                        }
                        if (isset($details['fs_version']) && is_string($details['fs_version'])) {
                            $detail['type'] = 'db_fs_version';
                            $detail[] = [
                                'icon' => 'icon-flow-branch',
                                'text' => 'filesystem : ' . $details['fs_version'],
                            ];
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
            $detail = [
                'type' => 'from_to',
                [
                    'icon' => 'icon-flow-branch',
                    'text' => $details['from_version'],
                ],
                [
                    'icon' => isset($details['to_version']) ? 'icon-flow-branch' : 'icon-block',
                    'text' => $details['to_version'] ?? ($details['result'] ?? ''),
                ],
            ];
        } elseif (isset($details['version'])) {
            $detail = [
                'type' => 'version',
                'icon' => 'icon-flow-branch',
                'text' => $details['version'],
            ];
        } elseif (isset($details['result'])) {
            $detail = [
                'type' => 'error',
                'icon' => 'icon-block',
                'text' => $details['result'],
            ];
        }

        // Format our data before send
        // This data will be manipulate by maintenance_sys.js
        [$date, $hour] = explode(' ', $row->occuredOn);

        return [
            'major_infos' => $major_infos,
            'id' => $row->activityId,
            'object_icon' => $object_icon,
            'object' => ucwords($object),
            'action_icon' => $action_icon,
            'action_color' => $action_color,
            'action' => $action,
            'user_id' => $row->performedBy,
            'username' => $row->username,
            'date' => DateHelper::formatDate($date),
            'hour' => $hour,
            'detail' => $detail,
        ];
    }
}
