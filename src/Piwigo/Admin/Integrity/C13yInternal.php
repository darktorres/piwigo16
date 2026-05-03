<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

class C13yInternal
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
     *  object
     */
    public function c13y_version(\Piwigo\Admin\Integrity\CheckIntegrity $c13y): void
    {
        $check_list = [];

        $check_list[] = [
            'type' => 'PHP',
            'current' => phpversion(),
            'required' => REQUIRED_PHP_VERSION,
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
          .'<br>'.
          $c13y->get_htlm_links_more_info()
                );
            }
        }
    }

    /**
     * Check exif
     *
     *  object
     */
    public function c13y_exif(\Piwigo\Admin\Integrity\CheckIntegrity $c13y): void
    {
        foreach (['show_exif', 'use_exif'] as $value) {
            if ((\Piwigo\Config\Config::raw($value)) and (!function_exists('exif_read_data'))) {
                $c13y->add_anomaly(
                    sprintf(l10n('%s value is not correct file because exif are not supported'), '$' . 'conf[\''.$value.'\']'),
                    null,
                    null,
                    sprintf(l10n('%s must be to set to false in your local/config/config.inc.php file'), '$' . 'conf[\''.$value.'\']')
          .'<br>'.
          $c13y->get_htlm_links_more_info()
                );
            }
        }
    }

    /**
     * Check user
     *
     *  object
     */
    public function c13y_user(\Piwigo\Admin\Integrity\CheckIntegrity $c13y): void
    {
        $c13y_users = [];
        $c13y_users[\Piwigo\Config\Config::guestId()] = [
          'status' => 'guest',
          'l10n_non_existent' => 'Main "guest" user does not exist',
          'l10n_bad_status' => 'Main "guest" user status is incorrect'];

        if (\Piwigo\Config\Config::guestId() != \Piwigo\Config\Config::defaultUserId()) {
            $c13y_users[\Piwigo\Config\Config::defaultUserId()] = [
              'password' => null,
              'l10n_non_existent' => 'Default user does not exist'];
        }

        $c13y_users[\Piwigo\Config\Config::webmasterId()] = [
          'status' => 'webmaster',
          'l10n_non_existent' => 'Main "webmaster" user does not exist',
          'l10n_bad_status' => 'Main "webmaster" user status is incorrect'];

        $query = '
  select u.'.\Piwigo\Config\Config::userFields()['id'].' as id, ui.status
  from '.USERS_TABLE.' as u
    left join '.USER_INFOS_TABLE.' as ui
        on u.'.\Piwigo\Config\Config::userFields()['id'].' = ui.user_id
  where
    u.'.\Piwigo\Config\Config::userFields()['id'].' in ('.implode(',', array_keys($c13y_users)).')
  ;';


        $status = [];

        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            $status[(int)$row['id']] = $row['status'];
        }

        foreach ($c13y_users as $id => $data) {
            if (!array_key_exists($id, $status)) {
                $c13y->add_anomaly(
                    l10n($data['l10n_non_existent']),
                    [$this, 'c13y_correction_user'],
                    ['id' => $id, 'action' => 'creation']
                );
            } elseif (!empty($data['status']) and $status[$id] != $data['status']) {
                $c13y->add_anomaly(
                    l10n($data['l10n_bad_status']),
                    [$this, 'c13y_correction_user'],
                    ['id' => $id, 'action' => 'status']
                );
            }
        }
    }

    /**
     * Do correction user
     *
     **  mixed
     *  string
     * @return boolean true if ok else false
     */
    public function c13y_correction_user(int $id, string $action): bool
    {
        $page = &$GLOBALS['page'];

        $result = false;

        if (!empty($id)) {
            switch ($action) {
                case 'creation':
                    $password = null;
                    if ($id == \Piwigo\Config\Config::guestId()) {
                        $name = 'guest';
                    } elseif ($id == \Piwigo\Config\Config::defaultUserId()) {
                        $name = 'guest';
                    } elseif ($id == \Piwigo\Config\Config::webmasterId()) {
                        $name = 'webmaster';
                        $password = generate_key(6);
                    }

                    if (isset($name)) {
                        $name_ok = false;
                        while (!$name_ok) {
                            $name_ok = (get_userid($name) === false);
                            if (!$name_ok) {
                                $name .= generate_key(1);
                            }
                        }

                        $inserts = [
                          [
                            'id'       => $id,
                            'username' => addslashes($name),
                            'password' => $password,
                            ],
                          ];
                        mass_inserts(USERS_TABLE, array_keys($inserts[0]), $inserts);

                        create_user_infos($id);

                        \Piwigo\Core\PageState::current()->addInfo(sprintf(l10n('User "%s" created with "%s" like password'), $name, $password));

                        $result = true;
                    }
                    break;
                case 'status':
                    if ($id == \Piwigo\Config\Config::guestId()) {
                        $status = 'guest';
                    } elseif ($id == \Piwigo\Config\Config::defaultUserId()) {
                        $status = 'guest';
                    } elseif ($id == \Piwigo\Config\Config::webmasterId()) {
                        $status = 'webmaster';
                    }

                    if (isset($status)) {
                        $updates = [
                          [
                            'user_id' => $id,
                            'status'  => $status,
                            ],
                          ];
                        mass_updates(
                            USER_INFOS_TABLE,
                            ['primary' => ['user_id'],'update' => ['status']],
                            $updates
                        );

                        \Piwigo\Core\PageState::current()->addInfo(sprintf(l10n('Status of user "%s" updated'), get_username($id)));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
