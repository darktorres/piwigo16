<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_user;
use Random\RandomException;

class c13y_internal
{
    public function __construct()
    {
        functions_plugins::add_event_handler('list_check_integrity', $this->c13y_version(...));
        functions_plugins::add_event_handler('list_check_integrity', $this->c13y_exif(...));
        functions_plugins::add_event_handler('list_check_integrity', $this->c13y_user(...));
    }

    /**
     * Check version
     *
     * @param check_integrity $c13y
     */
    public function c13y_version($c13y)
    {
        global $conf;

        $check_list = [];

        $check_list[] = [
            'type' => 'PHP',
            'current' => PHP_VERSION,
            'required' => REQUIRED_PHP_VERSION,
        ];

        $check_list[] = [
            'type' => 'MySQL',
            'current' => functions_mysqli::pwg_get_db_version(),
            'required' => functions_mysqli::REQUIRED_MYSQL_VERSION,
        ];

        foreach ($check_list as $elem) {
            if (version_compare($elem['current'], $elem['required'], '<')) {
                $c13y->add_anomaly(
                    sprintf(functions::l10n('The version of %s [%s] installed is not compatible with the version required [%s]'), $elem['type'], $elem['current'], $elem['required']),
                    null,
                    null,
                    functions::l10n('You need to upgrade your system to take full advantage of the application else the application will not work correctly, or not at all')
          . '<br>' .
          $c13y->get_html_links_more_info()
                );
            }
        }
    }

    /**
     * Check exif
     *
     * @param check_integrity $c13y
     */
    public function c13y_exif($c13y)
    {
        global $conf;

        foreach (['show_exif', 'use_exif'] as $value) {
            if ($conf[$value] and
                ! function_exists('exif_read_data')
            ) {
                $c13y->add_anomaly(
                    sprintf(functions::l10n('%s value is not correct file because exif are not supported'), '$conf[\'' . $value . '\']'),
                    null,
                    null,
                    sprintf(functions::l10n('%s must be to set to false in your local/config/config.php file'), '$conf[\'' . $value . '\']')
          . '<br>' .
          $c13y->get_html_links_more_info()
                );
            }
        }
    }

    /**
     * Check user
     *
     * @param check_integrity $c13y
     */
    public function c13y_user($c13y)
    {
        global $conf;

        $c13y_users = [];
        $c13y_users[$conf['guest_id']] = [
            'status' => 'guest',
            'l10n_non_existent' => 'Main "guest" user does not exist',
            'l10n_bad_status' => 'Main "guest" user status is incorrect',
        ];

        if ($conf['guest_id'] != $conf['default_user_id']) {
            $c13y_users[$conf['default_user_id']] = [
                'password' => null,
                'l10n_non_existent' => 'Default user does not exist',
            ];
        }

        $c13y_users[$conf['webmaster_id']] = [
            'status' => 'webmaster',
            'l10n_non_existent' => 'Main "webmaster" user does not exist',
            'l10n_bad_status' => 'Main "webmaster" user status is incorrect',
        ];

        $query = '
  select u.' . $conf['user_fields']['id'] . ' as id, ui.status
  from users as u
    left join user_infos as ui
        on u.' . $conf['user_fields']['id'] . ' = ui.user_id
  where
    u.' . $conf['user_fields']['id'] . ' in (' . implode(',', array_keys($c13y_users)) . ')
  ;';

        $status = [];

        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $status[$row['id']] = $row['status'];
        }

        foreach ($c13y_users as $id => $data) {
            if (! array_key_exists($id, $status)) {
                $c13y->add_anomaly(
                    functions::l10n($data['l10n_non_existent']),
                    'c13y_correction_user',
                    [
                        'id' => $id,
                        'action' => 'creation',
                    ]
                );
            } elseif (! empty($data['status']) and $status[$id] != $data['status']) {
                $c13y->add_anomaly(
                    functions::l10n($data['l10n_bad_status']),
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
     * @param mixed $id
     * @param mixed $action
     * @return bool true if ok else false
     * @throws RandomException
     */
    public function c13y_correction_user($id, $action)
    {
        global $conf, $page;

        $result = false;

        if (! empty($id)) {
            switch ($action) {
                case 'creation':
                    if ($id == $conf['guest_id']) {
                        $name = 'guest';
                        $password = null;
                    } elseif ($id == $conf['default_user_id']) {
                        $name = 'guest';
                        $password = null;
                    } elseif ($id == $conf['webmaster_id']) {
                        $name = 'webmaster';
                        $password = functions_session::generate_key(6);
                    }

                    if (isset($name)) {
                        $name_ok = false;

                        while (! $name_ok) {
                            $name_ok = (functions_user::get_userid($name) === false);

                            if (! $name_ok) {
                                $name .= functions_session::generate_key(1);
                            }
                        }

                        $inserts = [
                            [
                                'id' => $id,
                                'username' => addslashes($name),
                                'password' => $password,
                            ],
                        ];

                        functions_mysqli::mass_inserts('users', array_keys($inserts[0]), $inserts);

                        functions_user::create_user_infos($id);

                        $page['infos'][] = sprintf(functions::l10n('User "%s" created with "%s" like password'), $name, $password);

                        $result = true;
                    }

                    break;

                case 'status':
                    if ($id == $conf['guest_id']) {
                        $status = 'guest';
                    } elseif ($id == $conf['default_user_id']) {
                        $status = 'guest';
                    } elseif ($id == $conf['webmaster_id']) {
                        $status = 'webmaster';
                    }

                    if (isset($status)) {
                        $updates = [
                            [
                                'user_id' => $id,
                                'status' => $status,
                            ],
                        ];
                        functions_mysqli::mass_updates(
                            'user_infos',
                            [
                                'primary' => ['user_id'],
                                'update' => ['status'],
                            ],
                            $updates
                        );

                        $page['infos'][] = sprintf(functions::l10n('Status of user "%s" updated'), functions_admin::get_username($id));

                        $result = true;
                    }

                    break;
            }
        }

        return $result;
    }
}
