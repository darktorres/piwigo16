<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

use Piwigo\Core\AppInfo;
use Piwigo\Db\Tables;
use Piwigo\Session\SessionService;

class c13y_internal
{
    public function __construct()
    {
        add_event_handler('list_check_integrity', $this->c13y_version(...));
        add_event_handler('list_check_integrity', $this->c13y_exif(...));
        add_event_handler('list_check_integrity', $this->c13y_user(...));
    }

    /**
     * Check version
     *
     * @param check_integrity $c13y
     */
    public function c13y_version($c13y): void
    {
        global $conf;

        $check_list = [];

        $check_list[] = [
            'type' => 'PHP',
            'current' => PHP_VERSION,
            'required' => AppInfo::REQUIRED_PHP_VERSION,
        ];

        $check_list[] = [
            'type' => 'MySQL',
            'current' => pwg_get_db_version(),
            'required' => REQUIRED_MYSQL_VERSION,
        ];

        foreach ($check_list as $elem) {
            if (version_compare($elem['current'], $elem['required'], '<')) {
                $c13y->add_anomaly(
                    sprintf(l10n('The version of %s [%s] installed is not compatible with the version required [%s]'), $elem['type'], $elem['current'], $elem['required']),
                    null,
                    null,
                    l10n('You need to upgrade your system to take full advantage of the application else the application will not work correctly, or not at all')
          . '<br>' .
          $c13y->get_htlm_links_more_info()
                );
            }
        }
    }

    /**
     * Check exif
     *
     * @param check_integrity $c13y
     */
    public function c13y_exif($c13y): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        foreach (['show_exif', 'use_exif'] as $value) {
            if (((bool) $conf[$value]) and (! function_exists('exif_read_data'))) {
                $c13y->add_anomaly(
                    sprintf(l10n('%s value is not correct file because exif are not supported'), '$conf[\'' . $value . '\']'),
                    null,
                    null,
                    sprintf(l10n('%s must be to set to false in your local/config/config.inc.php file'), '$conf[\'' . $value . '\']')
          . '<br>' .
          $c13y->get_htlm_links_more_info()
                );
            }
        }
    }

    /**
     * Check user
     *
     * @param check_integrity $c13y
     */
    public function c13y_user($c13y): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = $conf['guest_id'];
        $guest_id = is_numeric($guest_id) ? (int) $guest_id : 0;

        $default_user_id = $conf['default_user_id'];
        $default_user_id = is_numeric($default_user_id) ? (int) $default_user_id : 0;

        $webmaster_id = $conf['webmaster_id'];
        $webmaster_id = is_numeric($webmaster_id) ? (int) $webmaster_id : 0;

        $c13y_users = [];
        $c13y_users[$guest_id] = [
            'status' => 'guest',
            'l10n_non_existent' => 'Main "guest" user does not exist',
            'l10n_bad_status' => 'Main "guest" user status is incorrect',
        ];

        if ($guest_id != $default_user_id) {
            $c13y_users[$default_user_id] = [
                'password' => null,
                'l10n_non_existent' => 'Default user does not exist',
            ];
        }

        $c13y_users[$webmaster_id] = [
            'status' => 'webmaster',
            'l10n_non_existent' => 'Main "webmaster" user does not exist',
            'l10n_bad_status' => 'Main "webmaster" user status is incorrect',
        ];

        // $conf['user_fields'] maps generic field names to table-specific DB
        // column names (see include/config_default.inc.php); always a
        // string=>string map at runtime.
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];
        $user_id_field = $user_fields['id'];

        $query = '
  select u.' . $user_id_field . ' as id, ui.status
  from ' . Tables::users() . ' as u
    left join ' . Tables::userInfos() . ' as ui
        on u.' . $user_id_field . ' = ui.user_id
  where
    u.' . $user_id_field . ' in (' . implode(',', array_keys($c13y_users)) . ')
  ;';

        $status = [];

        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            if (! is_string($row['id'])) {
                continue;
            }

            $status[$row['id']] = $row['status'];
        }

        foreach ($c13y_users as $id => $data) {
            if (! array_key_exists($id, $status)) {
                $c13y->add_anomaly(
                    l10n($data['l10n_non_existent']),
                    'c13y_correction_user',
                    [
                        'id' => $id,
                        'action' => 'creation',
                    ]
                );
            } elseif (! empty($data['status']) and $status[$id] != $data['status']) {
                $c13y->add_anomaly(
                    l10n($data['l10n_bad_status']),
                    'c13y_correction_user',
                    [
                        'id' => $id,
                        'action' => 'status',
                    ]
                );
            }
        }
    }

    /**
     * Do correction user
     *
     * @param int $id user_id
     * @param string $action
     * @return bool true if ok else false
     */
    public function c13y_correction_user($id, $action)
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         */
        global $conf, $page;

        // $page['infos'] is always initialized to an array by common.inc.php,
        // but that isn't visible across the include() boundary -- narrow it
        // once here so every $page['infos'][] = ... append below type-checks.
        $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = $conf['guest_id'];
        $guest_id = is_numeric($guest_id) ? (int) $guest_id : 0;

        $default_user_id = $conf['default_user_id'];
        $default_user_id = is_numeric($default_user_id) ? (int) $default_user_id : 0;

        $webmaster_id = $conf['webmaster_id'];
        $webmaster_id = is_numeric($webmaster_id) ? (int) $webmaster_id : 0;

        $result = false;

        if (! empty($id)) {
            switch ($action) {
                case 'creation':
                    $name = null;
                    $password = null;

                    if ($id == $guest_id) {
                        $name = 'guest';
                    } elseif ($id == $default_user_id) {
                        $name = 'guest';
                    } elseif ($id == $webmaster_id) {
                        $name = 'webmaster';
                        $password = SessionService::get()->generateKey(6);
                    }

                    if (isset($name)) {
                        $name_ok = false;
                        while (! $name_ok) {
                            $name_ok = ((new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->getUserId($name) === false);
                            if (! $name_ok) {
                                $name .= SessionService::get()->generateKey(1);
                            }
                        }

                        $inserts = [
                            [
                                'id' => $id,
                                'username' => addslashes($name),
                                'password' => $password,
                            ],
                        ];
                        mass_inserts(Tables::users(), array_keys($inserts[0]), $inserts);

                        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->createUserInfos($id);

                        $page['infos'][] = sprintf(l10n('User "%s" created with "%s" like password'), $name, $password);

                        $result = true;
                    }
                    break;
                case 'status':
                    if ($id == $guest_id) {
                        $status = 'guest';
                    } elseif ($id == $default_user_id) {
                        $status = 'guest';
                    } elseif ($id == $webmaster_id) {
                        $status = 'webmaster';
                    }

                    if (isset($status)) {
                        $updates = [
                            [
                                'user_id' => $id,
                                'status' => $status,
                            ],
                        ];
                        mass_updates(
                            Tables::userInfos(),
                            [
                                'primary' => ['user_id'],
                                'update' => ['status'],
                            ],
                            $updates
                        );

                        $page['infos'][] = sprintf(l10n('Status of user "%s" updated'), get_username($id));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
