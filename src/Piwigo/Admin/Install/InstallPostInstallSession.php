<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\AdminUiHelper;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\Projection\MailArgs;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Http\HttpClientService;
use Piwigo\Http\SessionBootstrap;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;

/**
 * The post-install session/login/newsletter/credentials-mail sequence --
 * extracted out of InstallWizard::render()'s own former step-2 block. Pure
 * side effects (session handler registration, login cookie, preferences
 * save, fire-and-forget newsletter subscribe, credentials email); nothing
 * it computes is read again by the caller afterward, so this returns void.
 */
final class InstallPostInstallSession
{
    public function __construct(
        private readonly Lang $lang,
        private readonly CurrentConfig $currentConfig,
        private readonly CurrentUser $currentUser,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly Paths $paths,
        private readonly ConnectedWithSession $connectedWithSession,
        private readonly InstallServiceFactory $installServiceFactory,
    ) {}

    public function run(Connection $conn, string $language, bool $isNewsletterSubscribe, string $adminName, string $adminMail, bool $isSendCredentialsByMail): void
    {
        // See Piwigo\Http\SessionBootstrap (kept inline here: at this point
        // of the install InstallationFlag was only just marked active and
        // this block ran unconditionally in the original, without
        // SessionBootstrap::register()'s session_save_handler === 'db' guard)
        session_set_save_handler(new SessionHandler(new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), $this->currentConfig), InstallBootstrap::currentLogger()));
        if (function_exists('ini_set')) {
            ini_set('session.use_cookies', $this->currentConfig->sessionUseCookies);
            ini_set('session.use_only_cookies', $this->currentConfig->sessionUseOnlyCookies);
            ini_set('session.use_trans_sid', (int) $this->currentConfig->sessionUseTransSid);
            ini_set('session.cookie_httponly', 1);
            // [P44-M] Mirrors Piwigo\Http\SessionBootstrap::register()'s own
            // secure/samesite hardening -- see that method's docblock for why.
            if (SessionBootstrap::requestIsHttps()) {
                ini_set('session.cookie_secure', 1);
            }

            ini_set('session.cookie_samesite', 'Lax');
        }
        session_name($this->currentConfig->sessionName);
        session_set_cookie_params(0, new CookieService()->cookiePath());
        register_shutdown_function(session_write_close(...));

        $user = $this->installServiceFactory->userService($conn)
            ->buildUser(UserId::from(1));
        // build_user() returns array<string, mixed>; the 'id' key we just set
        // to the literal user id 1 doesn't retain that literal type through
        // the return, so narrow to what log_user() actually accepts.
        $raw_login_user_id = $user['id'];
        if (is_int($raw_login_user_id)) {
            $login_user_id = $raw_login_user_id;
        } elseif (is_string($raw_login_user_id) && is_numeric($raw_login_user_id)) {
            $login_user_id = $raw_login_user_id;
        } else {
            $login_user_id = false;
        }
        // This install flow never goes
        // through Bootstrap\UserBootstrap::initialize() (the only place
        // that normally syncs Users\CurrentUser from the session for a
        // request), so ActivityService::record()'s own
        // CurrentUser::wasRealUserResolved() check sees "no real user
        // resolved yet" for every activity logged this request --
        // including the 'login' row logUser() itself records internally,
        // which is why this sync must happen BEFORE calling it, not after.
        // $user (built just above, same array shape UserBootstrap::
        // initialize() uses) is already the right data; this mirrors that
        // method's own two calls verbatim.
        $this->currentUser->set(User::fromUserArray($user));
        $this->currentUser->markRealUserResolved();
        new AuthService(new AuthRepository(EntityManagerFactory::build($conn)), new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class)), PresentationAccessor::htmlService(), $this->installServiceFactory->passwordService($conn), new CookieService(), TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class), UserFailedLoginRepository::class), new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), $this->currentConfig), $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentConfig, $this->paths, EntityManagerFactory::build($conn), $this->connectedWithSession)
            ->logUser($login_user_id, false);
        $this->connectedWithSession->set(ConnectedWith::PwgUi);

        // Same reason: narrow 'preferences' to array without discarding
        // whatever getuserdata() already populated it with.
        $preferences = $user['preferences'] ?? null;
        $preferences = is_array($preferences) ? $preferences : [];
        $preferences['show_whats_new_' . VersionHelper::getBranchFromVersion(AppInfo::VERSION)] = false;
        $user['preferences'] = $preferences;

        // newsletter subscription
        if ($isNewsletterSubscribe) {
            // Fire-and-forget: the response content is never read, only the
            // request's side effect (subscribing $admin_mail) matters.
            HttpClientService::fetch(
                AdminUiHelper::getNewsletterSubscribeBaseUrl($language) . $adminMail,
                $this->currentConfig,
                [],
                [
                    'origin' => 'installation',
                ]
            );

            $preferences['show_newsletter_subscription'] = false;
            $user['preferences'] = $preferences;
        }

        // Sync CurrentUser before PreferencesService::save() reads it --
        // this install-time $user is a fresh buildUser(1) result, never
        // routed through RequestBootstrap/UserBootstrap's own sync calls.
        $this->currentUser->set(User::fromUserArray($user));

        new PreferencesService(new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig), $this->currentUser)
            ->save();

        // email notification
        if ($isSendCredentialsByMail) {
            $keyargs_content = [
                $this->lang->buildArgs('Hello %s,', $adminName),
                $this->lang->buildArgs('Welcome to your new installation of Piwigo!', ''),
                $this->lang->buildArgs('', ''),
                $this->lang->buildArgs('Here are your connection settings', ''),
                $this->lang->buildArgs('', ''),
                $this->lang->buildArgs('Link: %s', PresentationAccessor::urlService()->getAbsoluteRootUrl()),
                $this->lang->buildArgs('Username: %s', $adminName),
                $this->lang->buildArgs('Password: ********** (no copy by email)', ''),
                $this->lang->buildArgs('Email: %s', $adminMail),
                $this->lang->buildArgs('', ''),
                $this->lang->buildArgs('Don\'t hesitate to consult our forums for any help: %s', AppInfo::URL),
            ];

            PresentationAccessor::mailService()
                ->mail(
                    $adminMail,
                    new MailArgs(
                        subject: $this->lang->t('Just another Piwigo gallery'),
                        content: $this->lang->args($keyargs_content),
                        contentFormat: 'text/plain',
                    )
                );
        }
    }
}
