<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

use Piwigo\Admin\Integrity\Event\ListCheckIntegrity;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\SqlDialect;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;

final class C13yInternal
{
    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    /**
     * Legacy Coupling Retirement Phase 8, 8k: registration used to be a
     * constructor side effect -- every `new C13yInternal()` silently
     * registered 3 more closures on the shared EventDispatcher singleton,
     * with no way to construct the class (e.g. to call
     * c13y_correction_user() directly) without that side effect firing.
     * Split into an explicit method so registration only happens where a
     * caller actually asks for it -- the one real caller
     * (Controller\Admin\IntroSubController) calls this right after
     * construction, same as it always did, just visibly.
     */
    public function registerHandlers(): void
    {
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(ListCheckIntegrity::class, $this->c13y_version(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(ListCheckIntegrity::class, $this->c13y_exif(...));
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(ListCheckIntegrity::class, $this->c13y_user(...));
    }

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    /**
     * Check version
     */
    public function c13y_version(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;

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
     */
    public function c13y_exif(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;
        $checks = [
            'show_exif' => \Piwigo\Config\CurrentConfig::showExif(),
            'use_exif' => \Piwigo\Config\CurrentConfig::useExif(),
        ];
        foreach ($checks as $value => $enabled) {
            if ($enabled and (! function_exists('exif_read_data'))) {
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
     */
    public function c13y_user(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;

        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = \Piwigo\Config\CurrentConfig::guestId();

        $default_user_id = \Piwigo\Config\CurrentConfig::defaultUserId();

        $webmaster_id = \Piwigo\Config\CurrentConfig::webmasterId();

        $c13y_users = [];
        $c13y_users[$guest_id] = [
            'status' => 'guest',
            'l10n_non_existent' => 'Main "guest" user does not exist',
            'l10n_bad_status' => 'Main "guest" user status is incorrect',
        ];

        if ($guest_id !== $default_user_id) {
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

        // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific DB
        // column names (see include/config_default.inc.php); always a
        // string=>string map at runtime.
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $user_id_field = $user_fields['id'];

        $status = self::userService()->getStatusByIds($user_id_field, array_keys($c13y_users));

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
            } elseif (! in_array($data['status'] ?? null, [null, false, 0, '0', '', []], true) and ($status[$id] ?? '') !== $data['status']) {
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
        $guest_id = \Piwigo\Config\CurrentConfig::guestId();

        $default_user_id = \Piwigo\Config\CurrentConfig::defaultUserId();

        $webmaster_id = \Piwigo\Config\CurrentConfig::webmasterId();

        $result = false;

        if ($id !== 0) {
            switch ($action) {
                case 'creation':
                    $name = null;
                    $password = null;

                    if ($id === $guest_id) {
                        $name = 'guest';
                    } elseif ($id === $default_user_id) {
                        $name = 'guest';
                    } elseif ($id === $webmaster_id) {
                        $name = 'webmaster';
                        $password = $this->sessionService->generateKey(6);
                    }

                    if (isset($name)) {
                        $name_ok = false;
                        while (! $name_ok) {
                            $name_ok = (self::userService()->getUserId(\Piwigo\Common\ValueObject\Username::from($name)) === null);
                            if (! $name_ok) {
                                $name .= $this->sessionService->generateKey(1);
                            }
                        }

                        self::userService()->insertUserRow([
                            'id' => $id,
                            'username' => addslashes($name),
                            'password' => $password,
                        ]);

                        self::userService()->createUserInfos([\Piwigo\Common\ValueObject\UserId::from($id)]);

                        \Piwigo\Core\PageState::current()->addInfo(sprintf(Lang::t('User "%s" created with "%s" like password'), $name, $password ?? ''));

                        $result = true;
                    }
                    break;
                case 'status':
                    if ($id === $guest_id) {
                        $status = 'guest';
                    } elseif ($id === $default_user_id) {
                        $status = 'guest';
                    } elseif ($id === $webmaster_id) {
                        $status = 'webmaster';
                    }

                    if (isset($status)) {
                        self::userService()->updateStatusForUsers([\Piwigo\Common\ValueObject\UserId::from($id)], $status);

                        $updated_username = self::userService()->getUsername(\Piwigo\Common\ValueObject\UserId::from($id));
                        \Piwigo\Core\PageState::current()->addInfo(sprintf(Lang::t('Status of user "%s" updated'), $updated_username === null ? '' : $updated_username->value));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
