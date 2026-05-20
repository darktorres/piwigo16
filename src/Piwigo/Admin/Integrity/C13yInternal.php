<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final class C13yInternal
{
    public function __construct()
    {
        // c13yVersion/c13yExif/c13yUser listeners now register at boot via
        // Piwigo\Listener\ListCheckIntegritySubscriber.
    }

    /**
     * Check version
     *
     *  object
     */
    public function c13yVersion(CheckIntegrity $c13y): void
    {
        $check_list = [];

        // Use the PHP_VERSION constant (always string) instead of
        // phpversion(), whose return type stubs disagree between PHPStan
        // (string) and Psalm (string|false). Both tools accept the
        // constant cleanly.
        $check_list[] = [
            'type' => 'PHP',
            'current' => PHP_VERSION,
            'required' => AppInfo::REQUIRED_PHP_VERSION,
            ];

        $check_list[] = [
            'type' => 'MySQL',
            'current' => DbInfo::version(),
            'required' => AppInfo::REQUIRED_MYSQL_VERSION,
            ];

        foreach ($check_list as $elem) {
            if (version_compare($elem['current'], $elem['required'], '<')) {
                $c13y->addAnomaly(
                    sprintf(Lang::t('The version of %s [%s] installed is not compatible with the version required [%s]'), $elem['type'], $elem['current'], $elem['required']),
                    null,
                    null,
                    Lang::t('You need to upgrade your system to take full advantage of the application else the application will not work correctly, or not at all')
          .'<br>'.
          $c13y->getHtlmLinksMoreInfo()
                );
            }
        }
    }

    /**
     * Check exif
     *
     *  object
     */
    public function c13yExif(CheckIntegrity $c13y): void
    {
        if (!function_exists('exif_read_data')) {
            if (Config::showExif()) {
                $c13y->addAnomaly(
                    sprintf(Lang::t('%s value is not correct file because exif are not supported'), '$conf[\'show_exif\']'),
                    null,
                    null,
                    sprintf(Lang::t('Install the PHP exif extension, or set %s to false in the database config table'), '$conf[\'show_exif\']')
                    . '<br>' . $c13y->getHtlmLinksMoreInfo()
                );
            }
            if (Config::useExif()) {
                $c13y->addAnomaly(
                    sprintf(Lang::t('%s value is not correct file because exif are not supported'), '$conf[\'use_exif\']'),
                    null,
                    null,
                    sprintf(Lang::t('Install the PHP exif extension, or set %s to false in the database config table'), '$conf[\'use_exif\']')
                    . '<br>' . $c13y->getHtlmLinksMoreInfo()
                );
            }
        }
    }

    /**
     * Check user
     *
     *  object
     */
    public function c13yUser(CheckIntegrity $c13y): void
    {
        $c13y_users = [];
        $c13y_users[Config::guestId()] = [
          'status' => 'guest',
          'l10n_non_existent' => 'Main "guest" user does not exist',
          'l10n_bad_status' => 'Main "guest" user status is incorrect'];

        if (Config::guestId() != Config::defaultUserId()) {
            $c13y_users[Config::defaultUserId()] = [
              'password' => null,
              'l10n_non_existent' => 'Default user does not exist'];
        }

        $c13y_users[Config::webmasterId()] = [
          'status' => 'webmaster',
          'l10n_non_existent' => 'Main "webmaster" user does not exist',
          'l10n_bad_status' => 'Main "webmaster" user status is incorrect'];

        $idField = Config::userFields()->id;
        $status  = Kernel::service(UserRepository::class)->findStatusByUserIds(
            Tables::users(),
            $idField,
            array_map(intval(...), array_keys($c13y_users)),
        );

        foreach ($c13y_users as $id => $data) {
            if (!array_key_exists($id, $status)) {
                $c13y->addAnomaly(
                    Lang::t($data['l10n_non_existent']),
                    $this->c13yCorrectionUser(...),
                    ['id' => $id, 'action' => 'creation']
                );
            } elseif (!empty($data['status']) and $status[$id] != $data['status']) {
                $c13y->addAnomaly(
                    Lang::t($data['l10n_bad_status']),
                    $this->c13yCorrectionUser(...),
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
    public function c13yCorrectionUser(int $id, string $action): bool
    {
        $result = false;

        if (!empty($id)) {
            switch ($action) {
                case 'creation':
                    $password = null;
                    if ($id == Config::guestId()) {
                        $name = 'guest';
                    } elseif ($id == Config::defaultUserId()) {
                        $name = 'guest';
                    } elseif ($id == Config::webmasterId()) {
                        $name = 'webmaster';
                        $password = StringUtil::generateKey(6);
                    }

                    if (isset($name)) {
                        $name_ok = false;
                        while (!$name_ok) {
                            $name_ok = (Kernel::service(UserService::class)->getUserid($name) === false);
                            if (!$name_ok) {
                                $name .= StringUtil::generateKey(1);
                            }
                        }

                        Kernel::service(UserRepository::class)->insertNew(
                            Tables::users(),
                            [
                                'id'       => $id,
                                'username' => addslashes($name),
                                'password' => $password,
                            ],
                        );

                        Kernel::service(UserService::class)->createUserInfos($id);

                        PageState::current()->addInfo(sprintf(Lang::t('User "%s" created with "%s" like password'), $name, (string) $password));

                        $result = true;
                    }
                    break;
                case 'status':
                    if ($id == Config::guestId()) {
                        $status = 'guest';
                    } elseif ($id == Config::defaultUserId()) {
                        $status = 'guest';
                    } elseif ($id == Config::webmasterId()) {
                        $status = 'webmaster';
                    }

                    if (isset($status)) {
                        Kernel::service(UserRepository::class)->updateUserInfosById($id, ['status' => $status]);

                        $usernameResult = Kernel::service(UserAdminService::class)->getUsername($id);
                        PageState::current()->addInfo(sprintf(Lang::t('Status of user "%s" updated'), $usernameResult !== false ? $usernameResult : (string) $id));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
