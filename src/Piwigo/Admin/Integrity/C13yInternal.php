<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Users\UserService;

final class C13yInternal
{
    public function __construct()
    {
        EventDispatcher::addListener('list_check_integrity', $this->c13yVersion(...));
        EventDispatcher::addListener('list_check_integrity', $this->c13yExif(...));
        EventDispatcher::addListener('list_check_integrity', $this->c13yUser(...));
    }

    /**
     * Check version
     *
     *  object
     */
    public function c13yVersion(CheckIntegrity $c13y): void
    {
        $check_list = [];

        $phpVer = phpversion();
        /** @phpstan-ignore-next-line notIdentical.alwaysTrue */
        $phpVerStr = $phpVer !== false ? $phpVer : '0.0.0';
        $check_list[] = [
            'type' => 'PHP',
            'current' => $phpVerStr,
            'required' => AppInfo::REQUIRED_PHP_VERSION,
            ];

        $check_list[] = [
            'type' => 'MySQL',
            'current' => DbInfo::version(),
            'required' => Dml::REQUIRED_MYSQL_VERSION,
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
        foreach (['show_exif', 'use_exif'] as $value) {
            if ((Config::raw($value)) and (!function_exists('exif_read_data'))) {
                $c13y->addAnomaly(
                    sprintf(Lang::t('%s value is not correct file because exif are not supported'), '$' . 'conf[\''.$value.'\']'),
                    null,
                    null,
                    sprintf(Lang::t('Install the PHP exif extension, or set %s to false in the database config table'), '$' . 'conf[\''.$value.'\']')
          .'<br>'.
          $c13y->getHtlmLinksMoreInfo()
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

        $idField = Config::userFields()['id'];
        $conn = ServiceLocator::get(Connection::class);
        $qb = $conn->createQueryBuilder()
            ->select("u.$idField AS id", 'ui.status')
            ->from(Tables::users(), 'u')
            ->leftJoin('u', Tables::userInfos(), 'ui', "u.$idField = ui.user_id");
        $qb->where($qb->expr()->in("u.$idField", ':ids'))
           ->setParameter('ids', array_keys($c13y_users), ArrayParameterType::INTEGER);

        $status = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $status[is_numeric($row['id']) ? (int)$row['id'] : 0] = $row['status'];
        }

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
        $page = &$GLOBALS['page'];

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
                            $name_ok = (UserService::get()->getUserid($name) === false);
                            if (!$name_ok) {
                                $name .= StringUtil::generateKey(1);
                            }
                        }

                        $inserts = [
                          [
                            'id'       => $id,
                            'username' => addslashes($name),
                            'password' => $password,
                            ],
                          ];
                        Dml::massInserts(Tables::users(), array_keys($inserts[0]), $inserts);

                        UserService::get()->createUserInfos($id);

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
                        $updates = [
                          [
                            'user_id' => $id,
                            'status'  => $status,
                            ],
                          ];
                        Dml::massUpdates(
                            Tables::userInfos(),
                            ['primary' => ['user_id'],'update' => ['status']],
                            $updates
                        );

                        $usernameResult = ServiceLocator::get(UserAdminService::class)->getUsername($id);
                        PageState::current()->addInfo(sprintf(Lang::t('Status of user "%s" updated'), $usernameResult !== false ? $usernameResult : (string) $id));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
