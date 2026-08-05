<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AdminContext;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocEndNoPhotoYet;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Page\Request\NoPhotoYetRequest;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

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
 *
 * Legacy Coupling Retirement Phase 8, 8d: its no_photo_yet write takes a
 * real constructor-injected ConfigService -- only 2 real construction
 * sites (Bootstrap\RequestBootstrap.php's own `new NoPhotoYetRenderer(...)
 * ->render()` inline call, gated on CurrentConfig::noPhotoYet() === null, plus
 * this class's own test), low enough to thread properly rather than reach
 * for CurrentConfigService::get().
 */
final readonly class NoPhotoYetRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private ImageRepository $imageRepository,
        private ConfigService $configService,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly Paths $paths,
        private readonly AdminContext $adminContext,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly MailerInterface $mailer,
        private readonly CurrentConfig $currentConfig,
        private readonly PageState $pageState,
        private readonly ErrorCollector $errorCollector,
        private readonly ProcessCache $processCache,
        private readonly CurrentConfigService $currentConfigService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly InstallationFlag $installationFlag,
    ) {}

    public function render(): void
    {
        if (
            ! $this->adminContext->isActive()   // no message inside administration
            and PageFilterHelper::scriptBasename() !== 'identification' // keep the ability to login
            and PageFilterHelper::scriptBasename() !== 'password'       // keep the ability to reset password
            and PageFilterHelper::scriptBasename() !== 'ws'             // keep the ability to discuss with web API
            and PageFilterHelper::scriptBasename() !== 'popuphelp'      // keep the ability to display help popups
            and ($this->accessControl->isAGuest() or $this->accessControl->isAdmin())          // normal users are not concerned by no_photo_yet
            and ! isset($_SESSION['no_photo_yet'])     // temporary hide
        ) {
            $nb_photos = $this->imageRepository->countAllImages();

            if ($nb_photos === 0) {
                // make sure we don't use the mobile theme, which is not compatible with
                // the "no photo yet" feature
                $user_theme = $this->currentUser->get()
                    ->theme;
                $user_theme = $user_theme !== '' ? $user_theme : new UserService($this->lang, new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig), EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class), $this->mailer, new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)), $this->htmlRenderer, DbConnection::build(), $this->sessionService, $this->eventDispatcher, $this->deploymentPolicy, $this->currentUser, $this->currentConfig, $this->installationFlag, $this->processCache)->getDefaultTheme();
                $template = new Template($this->currentConfig, $this->lang, $this->adminContext, $this->eventDispatcher, $this->pageState, $this->errorCollector, $this->processCache, $this->currentConfigService, $this->paths->root . 'themes', $user_theme);
                $this->currentTemplate->set($template);

                $noPhotoYetAction = NoPhotoYetRequest::fromGlobals()->action;
                if ($noPhotoYetAction === 'browse') {
                    $_SESSION['no_photo_yet'] = 'browse';
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                if ($noPhotoYetAction === 'deactivate') {
                    $this->configService->confUpdateParam('no_photo_yet', 'false');
                    $this->redirectService->redirect($this->urlService->makeIndexUrl());
                }

                header('Content-Type: text/html; charset=' . CharsetHelper::getPwgCharset());
                $template->set_filenames([
                    'no_photo_yet' => 'no_photo_yet.tpl',
                ]);

                if ($this->accessControl->isAdmin()) {
                    $url = $this->currentConfig->noPhotoYetUrl();
                    if (! str_starts_with($url, 'http')) {
                        $url = $this->urlService->getRootUrl() . $url;
                    }

                    $template->assign(
                        [
                            'step' => 2,
                            'intro' => $this->lang->t(
                                'Hello %s, your Piwigo photo gallery is empty!',
                                $this->currentUser->get()
                                    ->username
                            ),
                            'next_step_url' => $url,
                            'deactivate_url' => $this->urlService->getRootUrl() . '?no_photo_yet=deactivate',
                        ]
                    );
                } else {
                    $template->assign(
                        [
                            'step' => 1,
                            'U_LOGIN' => 'identification.php',
                            'deactivate_url' => $this->urlService->getRootUrl() . '?no_photo_yet=browse',
                        ]
                    );
                }

                $this->eventDispatcher->dispatchNotify(new LocEndNoPhotoYet());

                $template->pparse('no_photo_yet');
                exit();
            } else {
                $this->configService->confUpdateParam('no_photo_yet', 'false');
            }
        }
    }
}
