<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Admin\Integrity\check_integrity;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Db\DbConnection;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\Template;

/**
 * Consolidates the ~18-case maintenance action switch that was previously
 * duplicated, byte-for-byte at first but since drifted, between
 * admin/maintenance_actions.php and admin/maintenance_env.php (P23 batch
 * 6h). Both tab templates only ever link to a subset of these actions
 * (confirmed via direct grep of both .tpl files) -- actions.tpl to the 16
 * real maintenance operations, env.tpl to just `phpinfo`/`check_upgrade` --
 * but each legacy file's own switch handled the full set regardless, so
 * both tabs remain reachable exactly as before via this single shared
 * dispatcher.
 *
 * Fixes 2 real behavioral drifts found by diffing the two original
 * switches rather than trusting DbMaintenanceRepository's own docblock
 * claim that they were "identical":
 *  - admin/maintenance_env.php's copy never called pwg_activity() for any
 *    action -- so performing a real mutation via a crafted env-tab URL
 *    went completely unlogged in the sys tab's own audit view. This
 *    dispatcher always logs, matching admin/maintenance_actions.php's own
 *    (correct) behavior.
 *  - admin/maintenance_env.php's copy was missing the `empty_lounge` case
 *    entirely (silently fell through to `default:`). Restored here.
 *
 * `check_upgrade` also differed in one more way: admin/maintenance_env.php
 * (the only tab whose own UI actually links to this action) rendered a
 * real "Update to Piwigo %s" link when a new version is available;
 * admin/maintenance_actions.php's copy instead appended a generic "action
 * successfully performed." tail message that never made semantic sense for
 * this action (a copy-paste artifact of sitting directly above the shared
 * `default:` fallthrough, never reachable via that tab's own UI). This
 * dispatcher keeps the real, UI-exercised "Update to Piwigo %s" link
 * behavior as canonical.
 */
final class MaintenanceActionDispatcher
{
    public function dispatch(string $action): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var PersistentFileCache $persistent_cache
         * @var Template $template
         */
        global $conf, $page, $persistent_cache, $template;

        if (! is_array($page['infos'] ?? null)) {
            $page['infos'] = [];
        }
        if (! is_array($page['errors'] ?? null)) {
            $page['errors'] = [];
        }
        if (! is_array($page['warnings'] ?? null)) {
            $page['warnings'] = [];
        }

        $register_activity = true;
        $db_maintenance = new DbMaintenanceRepository(DbConnection::build());

        switch ($action) {
            case 'phpinfo':

                // SEC-22: curated info, not a raw phpinfo() dump -- see
                // ServerInfoService's own docblock.
                echo new ServerInfoService()
                    ->renderHtml();
                exit();

            case 'lock_gallery':

                conf_update_param('gallery_locked', 'true');
                pwg_activity('system', ActivitySystem::Core, 'maintenance', [
                    'maintenance_action' => $action,
                ]);
                redirect(get_root_url() . 'admin.php?page=maintenance');

                // no break
            case 'unlock_gallery':

                conf_update_param('gallery_locked', 'false');
                $_SESSION['page_infos'] = [l10n('Gallery unlocked')];
                pwg_activity('system', ActivitySystem::Core, 'maintenance', [
                    'maintenance_action' => $action,
                ]);
                redirect(get_root_url() . 'admin.php?page=maintenance');

                // no break
            case 'categories':

                images_integrity();
                categories_integrity();
                update_uppercats();
                update_category('all');
                update_global_rank();
                invalidate_user_cache(true);
                $page['infos'][] = sprintf('%s : %s', l10n('Update albums informations'), l10n('action successfully performed.'));
                break;

            case 'images':

                images_integrity();
                update_path();
                include_once PHPWG_ROOT_PATH . 'include/functions_rate.inc.php';
                update_rating_score();
                invalidate_user_cache();
                $page['infos'][] = sprintf('%s : %s', l10n('Update photos information'), l10n('action successfully performed.'));
                break;

            case 'delete_orphan_tags':

                delete_orphan_tags();
                $page['infos'][] = sprintf('%s : %s', l10n('Delete orphan tags'), l10n('action successfully performed.'));
                break;

            case 'user_cache':

                invalidate_user_cache();
                $page['infos'][] = sprintf('%s : %s', l10n('Purge user cache'), l10n('action successfully performed.'));
                break;

            case 'history_detail':

                $db_maintenance->purgeHistoryDetail();
                $page['infos'][] = sprintf('%s : %s', l10n('Purge history detail'), l10n('action successfully performed.'));
                break;

            case 'history_summary':

                $db_maintenance->purgeHistorySummary();
                $page['infos'][] = sprintf('%s : %s', l10n('Purge history summary'), l10n('action successfully performed.'));
                break;

            case 'sessions':

                pwg_session_gc();

                // $conf['user_fields'] maps generic field names to actual DB column
                // names (see include/config_default.inc.php); its values are
                // configuration-supplied, not statically typed, hence the fallback.
                $user_fields = $conf['user_fields'] ?? null;
                $user_fields = is_array($user_fields) ? $user_fields : [];
                $id_field = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';

                $db_maintenance->purgeSessionsForDeletedUsers($id_field);
                $page['infos'][] = sprintf('%s : %s', l10n('Purge sessions'), l10n('action successfully performed.'));
                break;

            case 'feeds':

                $db_maintenance->purgeUnusedFeeds();
                $page['infos'][] = sprintf('%s : %s', l10n('Purge never used notification feeds'), l10n('action successfully performed.'));
                break;

            case 'database':

                do_maintenance_all_tables();
                break;

            case 'c13y':

                $c13y = new check_integrity();
                $c13y->maintenance();
                $page['infos'][] = sprintf('%s : %s', l10n('Reinitialize check integrity'), l10n('action successfully performed.'));
                break;

            case 'empty_lounge':

                $rows = empty_lounge();
                $page['infos'][] = sprintf('%d photos were moved from the upload lounge to their albums', count($rows ?? []));
                break;

            case 'search':

                $db_maintenance->purgeSearchHistory();
                $page['infos'][] = sprintf('%s : %s', l10n('Purge search history'), l10n('action successfully performed.'));
                break;

            case 'compiled-templates':

                $template->delete_compiled_templates();
                FileCombiner::clear_combined_files();
                $persistent_cache->purge(true);
                $page['infos'][] = sprintf('%s : %s', l10n('Purge compiled templates'), l10n('action successfully performed.'));
                break;

            case 'derivatives':

                $types_str = $_GET['type'] ?? '';
                $types_str = is_string($types_str) ? $types_str : '';
                if ($types_str === 'all') {
                    clear_derivative_cache($types_str);
                } else {
                    $types = explode('_', $types_str);
                    foreach ($types as $type_to_clear) {
                        clear_derivative_cache($type_to_clear);
                    }
                }
                $page['infos'][] = l10n('action successfully performed.');
                break;

            case 'check_upgrade':

                $result = '';
                if (! fetchRemote(PHPWG_URL . '/download/latest_version', $result)) {
                    $page['errors'][] = l10n('Unable to check for upgrade.');
                } else {
                    $versions = [
                        'current' => AppInfo::VERSION,
                    ];
                    $lines = @explode("\r\n", (string) $result);

                    // if the current version is a BSF (development branch) build, we check
                    // the first line, for stable versions, we check the second line
                    if ((bool) preg_match('/^BSF/', $versions['current'])) {
                        $versions['latest'] = trim($lines[0]);

                        // because integer are limited to 4,294,967,296 we need to split BSF
                        // versions in date.time
                        foreach ($versions as $key => $value) {
                            $replaced = preg_replace('/BSF_(\d{8})(\d{4})/', '$1.$2', $value);
                            assert($replaced !== null);
                            $versions[$key] = $replaced;
                        }
                    } else {
                        $versions['latest'] = trim($lines[1]);
                    }

                    if ($versions['latest'] === '') {
                        $page['errors'][] = l10n('Check for upgrade failed for unknown reasons.');
                    } elseif (version_compare($versions['current'], $versions['latest']) < 0) {
                        $page['infos'][] = l10n('A new version of Piwigo is available.');

                        $update_url = PHPWG_ROOT_PATH . 'admin.php?page=updates';
                        $page['infos'][] = '<a href="' . $update_url . '">' . l10n('Update to Piwigo %s', $versions['latest']) . '</a>';
                    } else {
                        $page['infos'][] = l10n('You are running the latest version of Piwigo.');
                    }
                }

                // no break
            default:

                $register_activity = false;
                break;

        }

        if ($register_activity) {
            pwg_activity('system', ActivitySystem::Core, 'maintenance', [
                'maintenance_action' => $action,
            ]);
        }
    }
}
