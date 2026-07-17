<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;

/**
 * The "No Photo Yet" feature: if the gallery has no photo yet, replace
 * the whole page with a big box guiding the visitor/admin to add their
 * first photos. Contains real redirect()/exit() calls, same as
 * Html\HtmlService's own established precedent (see its
 * pageNotFound()-adjacent methods) -- not routed around, since this is
 * the original's real terminal behavior. Tests only exercise the
 * guard-condition-false and nb_photos>0 branches (never reach exit()),
 * same "don't stub what would kill the test process" reasoning as
 * fatal_error().
 */
final class NoPhotoYetRenderer
{
    public function __construct(
        private readonly Connection $conn,
    ) {}

    public function render(): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if (
            ! defined('IN_ADMIN')   // no message inside administration
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'identification' // keep the ability to login
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'password'       // keep the ability to reset password
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'ws'             // keep the ability to discuss with web API
            and \Piwigo\Core\PageFilterHelper::scriptBasename() !== 'popuphelp'      // keep the ability to display help popups
            and (\Piwigo\Auth\AccessControl::isAGuest() or \Piwigo\Auth\AccessControl::isAdmin())          // normal users are not concerned by no_photo_yet
            and ! isset($_SESSION['no_photo_yet'])     // temporary hide
        ) {
            $nb_photos = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . Tables::images())
                ->fetchOne();
            $nb_photos = is_numeric($nb_photos) ? (int) $nb_photos : 0;

            if ($nb_photos === 0) {
                // make sure we don't use the mobile theme, which is not compatible with
                // the "no photo yet" feature
                $user_theme = \Piwigo\Users\CurrentUser::get()->theme;
                $user_theme = $user_theme !== '' ? $user_theme : (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultTheme();
                /** @var Template $template */
                global $template;
                $template = new Template(PHPWG_ROOT_PATH . 'themes', $user_theme);
                \Piwigo\Template\CurrentTemplate::set($template);

                if (isset($_GET['no_photo_yet'])) {
                    if ($_GET['no_photo_yet'] === 'browse') {
                        $_SESSION['no_photo_yet'] = 'browse';
                        redirect(make_index_url());
                    }

                    if ($_GET['no_photo_yet'] === 'deactivate') {
                        \Piwigo\Config\ConfigDb::confUpdateParam('no_photo_yet', 'false');
                        redirect(make_index_url());
                    }
                }

                header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
                $template->set_filenames([
                    'no_photo_yet' => 'no_photo_yet.tpl',
                ]);

                if (\Piwigo\Auth\AccessControl::isAdmin()) {
                    $url = $conf['no_photo_yet_url'];
                    $url = is_string($url) ? $url : '';
                    if (! str_starts_with($url, 'http')) {
                        $url = get_root_url() . $url;
                    }

                    $template->assign(
                        [
                            'step' => 2,
                            'intro' => l10n(
                                'Hello %s, your Piwigo photo gallery is empty!',
                                \Piwigo\Users\CurrentUser::get()->username
                            ),
                            'next_step_url' => $url,
                            'deactivate_url' => get_root_url() . '?no_photo_yet=deactivate',
                        ]
                    );
                } else {
                    $template->assign(
                        [
                            'step' => 1,
                            'U_LOGIN' => 'identification.php',
                            'deactivate_url' => get_root_url() . '?no_photo_yet=browse',
                        ]
                    );
                }

                trigger_notify('loc_end_no_photo_yet');

                $template->pparse('no_photo_yet');
                exit();
            } else {
                \Piwigo\Config\ConfigDb::confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
