<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Integrity;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Admin\Integrity\Event\ListCheckIntegrity;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\SqlDialect;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\UserService;

final class C13yInternal
{
    public function __construct(
        private readonly Lang $lang,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly UserService $userService,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * Registers this class's 3 event handlers on EventDispatcher.
     * Construction alone does not register them; callers must invoke
     * this explicitly. The only real caller is
     * Controller\Admin\IntroSubController, which calls this right after
     * construction.
     */
    public function registerHandlers(): void
    {
        $this->eventDispatcher->addTypedHandler(ListCheckIntegrity::class, $this->c13yVersion(...));
        $this->eventDispatcher->addTypedHandler(ListCheckIntegrity::class, $this->c13yExif(...));
        $this->eventDispatcher->addTypedHandler(ListCheckIntegrity::class, $this->c13yUser(...));
    }

    /**
     * Check version
     */
    public function c13yVersion(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;

        $check_list = [];

        $check_list[] = [
            'type' => 'PHP',
            'current' => PHP_VERSION,
            'required' => AppInfo::REQUIRED_PHP_VERSION,
        ];

        $conn = DbConnection::build();
        $isPostgres = $conn->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $rawDbVersion = new DbInfo($conn)
            ->version();

        // Real bug found live -- Postgres's own
        // SELECT version() output is a full descriptive string
        // ("PostgreSQL 18.4 (Ubuntu ...) on x86_64-pc-linux-gnu,
        // compiled by gcc ..."), not a bare parseable version number the
        // way MySQL's is -- version_compare() against the raw string
        // always reported "less than" any real required version,
        // flagging a false anomaly on every real Postgres install.
        // Extracts just the leading X.Y(.Z) numeric version for the
        // comparison itself; the anomaly message (if one fires) still
        // reports the full raw string, unchanged -- more useful
        // diagnostic text for an admin reading it than a bare number.
        preg_match('/\d+(?:\.\d+){1,2}/', $rawDbVersion, $versionMatch);
        $comparableDbVersion = $versionMatch[0] ?? $rawDbVersion;

        $check_list[] = [
            'type' => $isPostgres ? 'PostgreSQL' : 'MySQL',
            'current' => $rawDbVersion,
            'compare' => $comparableDbVersion,
            'required' => $isPostgres ? SqlDialect::REQUIRED_POSTGRES_VERSION : SqlDialect::REQUIRED_MYSQL_VERSION,
        ];

        foreach ($check_list as $elem) {
            if (version_compare($elem['compare'] ?? $elem['current'], $elem['required'], '<')) {
                $c13y->addAnomaly(
                    sprintf($this->lang->t('The version of %s [%s] installed is not compatible with the version required [%s]'), $elem['type'], $elem['current'], $elem['required']),
                    null,
                    null,
                    $this->lang->t('You need to upgrade your system to take full advantage of the application else the application will not work correctly, or not at all')
          . '<br>' .
          $c13y->getHtlmLinksMoreInfo()
                );
            }
        }
    }

    /**
     * Check exif
     */
    public function c13yExif(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;
        $checks = [
            'show_exif' => $this->currentConfig->showExif,
            'use_exif' => $this->currentConfig->useExif,
        ];
        foreach ($checks as $value => $enabled) {
            if ($enabled and (! function_exists('exif_read_data'))) {
                $c13y->addAnomaly(
                    sprintf($this->lang->t('%s value is not correct file because exif are not supported'), '$conf[\'' . $value . '\']'),
                    null,
                    null,
                    sprintf($this->lang->t('%s must be to set to false in your local/config/config.inc.php file'), '$conf[\'' . $value . '\']')
          . '<br>' .
          $c13y->getHtlmLinksMoreInfo()
                );
            }
        }
    }

    /**
     * Check user
     */
    public function c13yUser(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;

        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = $this->currentConfig->guestId;

        $default_user_id = $this->currentConfig->defaultUserId;

        $webmaster_id = $this->currentConfig->webmasterId;

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

        $status = $this->userService->getStatusByIds(array_keys($c13y_users));

        foreach ($c13y_users as $id => $data) {
            if (! array_key_exists($id, $status)) {
                $c13y->addAnomaly(
                    $this->lang->t($data['l10n_non_existent']),
                    $this->c13yCorrectionUser(...),
                    [
                        'id' => $id,
                        'action' => 'creation',
                    ]
                );
            } elseif (($data['status'] ?? null) !== null and ($status[$id] ?? '') !== $data['status']) {
                $c13y->addAnomaly(
                    $this->lang->t($data['l10n_bad_status']),
                    $this->c13yCorrectionUser(...),
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
    public function c13yCorrectionUser($id, $action)
    {
        // guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // primary keys or config defaults, see include/config_default.inc.php).
        $guest_id = $this->currentConfig->guestId;

        $default_user_id = $this->currentConfig->defaultUserId;

        $webmaster_id = $this->currentConfig->webmasterId;

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
                            $name_ok = ($this->userService->getUserId(Username::from($name)) === null);
                            if (! $name_ok) {
                                $name .= $this->sessionService->generateKey(1);
                            }
                        }

                        $this->userService->insertUserRow(
                            UserId::from($id),
                            Username::from(addslashes($name)),
                            $password,
                        );

                        $this->userService->createUserInfos([UserId::from($id)]);

                        $this->pageState->addInfo(sprintf($this->lang->t('User "%s" created with "%s" like password'), $name, $password ?? ''));

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
                        $this->userService->updateStatusForUsers([UserId::from($id)], $status);

                        $updated_username = $this->userService->getUsername(UserId::from($id));
                        $this->pageState->addInfo(sprintf($this->lang->t('Status of user "%s" updated'), $updated_username === null ? '' : $updated_username->value));

                        $result = true;
                    }
                    break;
            }
        }

        return $result;
    }
}
