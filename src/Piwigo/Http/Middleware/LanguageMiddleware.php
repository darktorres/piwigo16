<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\ApiKeyService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AdminContext;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Event\LoadingLang;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fourth real per-request bootstrap middleware (workstream C3 Phase 1) --
 * verbatim-ported from the first half of `Bootstrap\RequestBootstrap::
 * finalize()` (language loading through the api_key-expiration
 * notification), up to but not including the `// template instance`
 * comment that starts the still-unconverted, Template-dependent second
 * half (theme resolution, `Template` construction, `NoPhotoYetRenderer`,
 * the gallery-locked 503 check) -- that remainder stays in
 * `RequestBootstrap::finalize()` for now, called from a thin bridge
 * middleware, gated on Plan 2 P38/P39 landing before it gets its own real
 * decomposition (workstream C3 Phase 2).
 *
 * Runs after `Bootstrap\UserResolutionMiddleware`: the guest-username
 * localization below has a real ordering requirement, not just a
 * textual one -- `finalize()`'s own original comment says so directly
 * ("only now we can set the localized username of the guest user (and not
 * in UserBootstrap::initialize())"), since it needs `common.lang` already
 * loaded by this same middleware, earlier in its own body.
 */
final readonly class LanguageMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Lang $lang,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private AccessLevelChecker $accessLevelChecker,
        private AdminContext $adminContext,
        private EventDispatcher $eventDispatcher,
        private Paths $paths,
        private PageState $pageState,
        private UrlServiceInterface $urlService,
        private HtmlService $htmlService,
        private SessionService $sessionService,
        private DeploymentPolicy $deploymentPolicy,
        private InstallationFlag $installationFlag,
        private ProcessCache $processCache,
        private MailerInterface $mailer,
        private FilterState $filterState,
        private Translator $translator,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $conn = DbConnection::build();

        // language files
        $this->lang->setDefaultLanguageProvider(new UserService(
            $this->lang,
            new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            $this->activityService($conn),
            $this->htmlService,
            $this->sessionService,
            $this->eventDispatcher,
            $this->deploymentPolicy,
            $this->currentUser,
            $this->currentConfig,
            $this->installationFlag,
            $this->processCache,
            $this->paths,
            EntityManagerFactory::build($conn),
            $this->permissionService($conn),
            $this->categoryService($conn),
            $this->passwordService($conn),
        ));
        $this->lang->load('common.lang');
        if ($this->accessLevelChecker->isAdmin() || $this->adminContext->isActive()) {
            $this->lang->load('admin.lang');
            // Add language for temporary strings for new popup, from piwigo 15
            $this->lang->load('whats_new_' . VersionHelper::getBranchFromVersion(AppInfo::VERSION) . '.lang');
        }
        $this->eventDispatcher->dispatch(new LoadingLang());
        $this->lang->load('lang', $this->paths->siteLocal, [
            'no_fallback' => true,
            'local' => true,
        ]);

        // only now we can set the localized username of the guest user (and not in
        // UserBootstrap::initialize())
        if ($this->accessLevelChecker->isAGuest()) {
            // Second CurrentUser sync point (the first is inside
            // UserBootstrap::initialize()) -- isAGuest() itself already
            // reads CurrentUser (synced there with the pre-localization
            // username), so only the localized-username case needs a
            // second sync; the non-guest path never mutates CurrentUser
            // again after initialize()'s own sync.
            $this->currentUser->set($this->currentUser->get()->withUsername(Username::from($this->lang->t('guest'))));
        }

        $pageState = $this->pageState;

        // in case an auth key was provided and is no longer valid, we must wait to
        // be here, with language loaded, to prepare the message
        if ($pageState->authKeyInvalid) {
            $pageState->addError(
                $this->lang->t('Your authentication key is no longer valid.')
              . sprintf(' <a href="%s">%s</a>', $this->urlService->getRootUrl() . 'identification.php', $this->lang->t('Login'))
            );
        }

        // check if we need to notified user about api_key expiration
        $notify_api_key_expiration = $pageState->notifyApiKeyExpiration;
        // This account data, though read from CurrentUser, is exactly as
        // much a "could be malformed/incomplete" boundary as raw input --
        // a real fixture/legacy account can have an empty email or
        // username -- so tryFrom() + a graceful skip, not a hard
        // requirement.
        $notify_username = $notify_api_key_expiration !== null ? Username::tryFrom($this->currentUser->get()->username) : null;
        $notify_email = $notify_api_key_expiration !== null ? Email::tryFrom($this->currentUser->get()->email) : null;
        if ($notify_api_key_expiration !== null && $notify_username instanceof Username && $notify_email instanceof Email) {
            $apiKeyRepo = new ApiKeyRepository(EntityManagerFactory::build($conn));
            $is_mail_send = new ApiKeyService($this->lang, $this->mailer, $apiKeyRepo, $this->passwordService($conn), $this->urlService, $this->sessionService, $this->currentConfig)
                ->notifyExpiration($notify_username, $notify_email, $notify_api_key_expiration['days_left']);

            if ($is_mail_send) {
                $apiKeyRepo->updateLastNotifiedOn(
                    $notify_api_key_expiration['auth_key'],
                    $this->currentUser->get()
                        ->id->value,
                    $notify_api_key_expiration['dbnow'],
                );
            }

            $pageState->notifyApiKeyExpiration = null;
        }

        return $handler->handle($request);
    }

    private function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class));
    }

    private function passwordService(Connection $conn): PasswordService
    {
        return new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), $this->deploymentPolicy);
    }

    private function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->currentUser, $this->filterState, $this->accessLevelChecker);
    }

    private function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService($this->lang, new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig), $this->permissionService($conn), $this->currentConfig, $this->eventDispatcher, $this->translator, $this->accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig));
    }
}
