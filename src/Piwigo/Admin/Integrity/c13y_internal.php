<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

class c13y_internal
{
    public function __construct()
    {
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('list_check_integrity', $this->c13y_version(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('list_check_integrity', $this->c13y_exif(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler('list_check_integrity', $this->c13y_user(...));
    }

    private static function userService(Connection $conn): UserService
    {
        return new UserService(
            new UserRepository($conn),
            new GroupRepository($conn),
            new MailService(),
            new ActivityService(new ActivityRepository($conn)),
            new HtmlService(),
            $conn
        );
    }

    /**
     * Check version
     *
     * @param check_integrity $c13y
     */
    public function c13y_version($c13y): void
    {

        $check_list = [];

        $check_list[] = [
            'type' => 'PHP',
            'current' => PHP_VERSION,
            'required' => AppInfo::REQUIRED_PHP_VERSION,
        ];

        $check_list[] = [
            'type' => 'MySQL',
            'current' => new DbInfo(DbConnection::build())->version(),
            'required' => SqlDialect::REQUIRED_MYSQL_VERSION,
        ];

        foreach ($check_list as $elem) {
            if (version_compare($elem['current'], $elem['required'], '<')) {
                $c13y->add_anomaly(
                    sprintf(Lang::t('The version of %s [%s] installed is not compatible with the version required [%s]'), $elem['type'], $elem['current'], $elem['required']),
                    null,
                    null,
                    Lang::t('You need to upgrade your system to take full advantage of the application else the application will not work correctly, or not at all')
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
        foreach (['show_exif', 'use_exif'] as $value) {
            if (((bool) (\Piwigo\Config\Config::all()[$value] ?? null)) and (! function_exists('exif_read_data'))) {
                $c13y->add_anomaly(
                    sprintf(Lang::t('%s value is not correct file because exif are not supported'), '$conf[\'' . $value . '\']'),
                    null,
                    null,
                    sprintf(Lang::t('%s must be to set to false in your local/config/config.inc.php file'), '$conf[\'' . $value . '\']')
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
        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = \Piwigo\Config\Config::guestId();

        $default_user_id = \Piwigo\Config\Config::defaultUserId();

        $webmaster_id = \Piwigo\Config\Config::webmasterId();

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

        // \Piwigo\Config\Config::userFields() maps generic field names to table-specific DB
        // column names (see include/config_default.inc.php); always a
        // string=>string map at runtime.
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();
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

        foreach (DbConnection::build()->fetchAllAssociative($query) as $row) {
            if (! is_int($row['id']) && ! is_string($row['id'])) {
                continue;
            }

            $status[$row['id']] = $row['status'];
        }

        foreach ($c13y_users as $id => $data) {
            if (! array_key_exists($id, $status)) {
                $c13y->add_anomaly(
                    Lang::t($data['l10n_non_existent']),
                    'c13y_correction_user',
                    [
                        'id' => $id,
                        'action' => 'creation',
                    ]
                );
            } elseif (! empty($data['status']) and (is_scalar($status[$id]) ? (string) $status[$id] : '') !== $data['status']) {
                $c13y->add_anomaly(
                    Lang::t($data['l10n_bad_status']),
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
        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = \Piwigo\Config\Config::guestId();

        $default_user_id = \Piwigo\Config\Config::defaultUserId();

        $webmaster_id = \Piwigo\Config\Config::webmasterId();

        $result = false;
        $conn = DbConnection::build();

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
                            $name_ok = (self::userService($conn)->getUserId($name) === false);
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
                        new BatchWriter($conn)
                            ->massInsert(Tables::users(), array_keys($inserts[0]), $inserts);

                        self::userService($conn)->createUserInfos($id);

                        \Piwigo\Core\PageState::current()->addInfo(sprintf(Lang::t('User "%s" created with "%s" like password'), $name, $password));

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
                        new BatchWriter($conn)
                            ->massUpdate(
                                Tables::userInfos(),
                                [
                                    'primary' => ['user_id'],
                                    'update' => ['status'],
                                ],
                                $updates
                            );

                        \Piwigo\Core\PageState::current()->addInfo(sprintf(Lang::t('Status of user "%s" updated'), self::userService($conn)->getUsername($id)));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
