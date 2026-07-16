<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Core\ActivitySystem;

/**
 * Formats one Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames() row into the icon/color/label/detail
 * shape the "sys" tab's `pwg.activity_sys.getList` ajax response needs.
 * Extracted from admin/maintenance_sys.php's own per-row body (P23 batch
 * 6h) -- pure data transformation given a row, zero DB/IO side effects, so
 * it's Unit-testable directly instead of only exercisable via a live
 * DB-backed Integration test, matching the established
 * MenubarPageRendererMakeConsecutiveTest/StatsPageRendererDateHelpersTest
 * precedent of extracting pure per-renderer helpers.
 */
final class ActivityLogEntryFormatter
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $maintActions
     *
     * @return array<string, mixed>
     */
    public function format(array $row, array $maintActions): array
    {
        $major_infos = false;
        $object = '';
        $object_icon = '';
        $action_icon = '';
        $action_color = '';
        $action = $row['action'];
        $details = is_string($row['details']) ? unserialize($row['details']) : false;
        $details = is_array($details) ? $details : [];
        $detail = [
            'type' => 'empty',
        ];

        // For each categories (Core, Plugin and Theme) we need to format theirs actions
        switch ($row['object_id']) {
            case ActivitySystem::Core:
                $object_icon = 'icon-piwigo';
                $object = l10n('Core');

                switch ($row['action']) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = l10n('Install');
                        break;

                    case 'config':
                        $action_icon = 'icon-cog-alt';
                        $action_color = 'icon-yellow';
                        $action = l10n('Configuration');
                        // for config we need to specific format details
                        if (isset($details['config_section'])) {
                            $c_icon = '';
                            $c_text = '';
                            switch ($details['config_section']) {
                                case 'main':
                                    $c_icon = 'icon-cog';
                                    $c_text = l10n('General');
                                    break;

                                case 'watermark':
                                    $c_icon = 'icon-file-image';
                                    $c_text = l10n('Watermark');
                                    break;

                                case 'sizes':
                                    $c_icon = 'icon-zoom-square';
                                    $c_text = l10n('Photo sizes');
                                    // sizes have 2 params always Photo sizes and sometimes config_action
                                    if (isset($details['config_action']) && $details['config_action'] === 'restore_settings') {
                                        $detail[] = [
                                            'icon' => 'icon-back-in-time',
                                            'text' => l10n('Set as default'),
                                        ];
                                    }
                                    break;

                                case 'comments':
                                    $c_icon = 'icon-chat';
                                    $c_text = l10n('Comments');
                                    break;

                                case 'display':
                                    $c_icon = 'icon-television';
                                    $c_text = l10n('Display');
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
                        $action = l10n('Maintenance');
                        // for maintenance we need to specific format details
                        if (isset($details['maintenance_action'])) {
                            $action_detail = $details['maintenance_action'];
                            // Array keys are only ever int|string; anything else
                            // (e.g. leftover legacy bool/array payloads) falls back
                            // to an empty-string key, which simply misses the lookup.
                            $action_detail_key = is_string($action_detail) || is_int($action_detail) ? $action_detail : '';
                            $maint_action_entry = $maintActions[$action_detail_key] ?? null;
                            $maint_action_entry = is_array($maint_action_entry) ? $maint_action_entry : [];
                            $detail = [
                                'type' => 'maintenance_action',
                                'icon' => is_string($maint_action_entry['icon'] ?? null) ? $maint_action_entry['icon'] : 'icon-cone',
                                'text' => $maint_action_entry['label'] ?? $action_detail,
                            ];
                        }
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = l10n('Update');
                        $major_infos = true;
                        break;

                    case 'autoupdate':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = l10n('Auto-update');
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
                switch ($row['action']) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = l10n('Install');
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = l10n('Update');
                        break;

                    case 'activate':
                        $action_icon = 'icon-check';
                        $action_color = 'icon-green';
                        $action = l10n('Activate');
                        break;

                    case 'deactivate':
                        $action_icon = 'icon-block';
                        $action_color = 'icon-purple';
                        $action = l10n('Deactivate');
                        break;

                    case 'uninstall':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = l10n('Uninstall');
                        break;

                    case 'restore':
                        $action_icon = 'icon-back-in-time';
                        $action_color = 'icon-blue';
                        $action = l10n('Restore');
                        break;

                    case 'delete':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = l10n('Delete');
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
                        $action = l10n('Auto-update');
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

                switch ($row['action']) {
                    case 'install':
                        $action_icon = 'icon-download';
                        $action_color = 'icon-green';
                        $action = l10n('Install');
                        break;

                    case 'activate':
                        $action_icon = 'icon-check';
                        $action_color = 'icon-green';
                        $action = l10n('Activate');
                        break;

                    case 'deactivate':
                        $action_icon = 'icon-block';
                        $action_color = 'icon-purple';
                        $action = l10n('Deactivate');
                        break;

                    case 'delete':
                        $action_icon = 'icon-trash-1';
                        $action_color = 'icon-red';
                        $action = l10n('Delete');
                        break;

                    case 'set_default':
                        $action_icon = 'icon-star';
                        $action_color = 'icon-yellow';
                        $action = l10n('Set as default');
                        break;

                    case 'update':
                        $action_icon = 'icon-arrows-cw';
                        $action_color = 'icon-blue';
                        $action = l10n('Update');
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
        $occured_on = is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '';
        [$date, $hour] = explode(' ', $occured_on);

        return [
            'major_infos' => $major_infos,
            'id' => $row['activity_id'],
            'object_icon' => $object_icon,
            'object' => ucwords($object),
            'action_icon' => $action_icon,
            'action_color' => $action_color,
            'action' => $action,
            'user_id' => $row['performed_by'],
            'username' => $row['username'],
            'date' => \Piwigo\Core\DateHelper::formatDate($date),
            'hour' => $hour,
            'detail' => $detail,
        ];
    }
}
