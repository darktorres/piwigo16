<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\LanguageStack;
use Piwigo\Core\Paths;
use Piwigo\Event\Lifecycle\LoadingLang;
use Piwigo\Lang\LangService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Session\Session;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Issues HTTP redirects, falling back to an HTML body with `<meta refresh>`
 * when headers have already been flushed or when the caller asked for a
 * delayed redirect (`$refreshTime > 0`).
 *
 * Keeps the historical exit-based semantics of `Util::redirect()` — each
 * method terminates the request via `exit()`. The inventory's original
 * design specified returning PSR-7 `ResponseInterface`, but converting
 * deep services (AuthService, UserService, PasswordService …) to bubble
 * Response up through the call chain is left for a future HTTP-pipeline
 * refactor.
 */
final readonly class RedirectResponder
{
    public function __construct(
        private LangService $langService,
        private EventDispatcherInterface $dispatcher,
        private Paths $paths,
        private Session $session,
    ) {
    }

    public function redirect(string $url, string $msg = '', int $refreshTime = 0): void
    {
        if (Config::defaultRedirectMethod() === 'http' && $refreshTime === 0 && !headers_sent()) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refreshTime);
        }
    }

    public function redirectHttp(string $url): void
    {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        $url = html_entity_decode($url);
        // Flush session changes before exit() — PHP's finally blocks do not
        // run on exit(), so SessionMiddleware::persistInto() would be skipped
        // without this explicit call.
        $this->session->persistInto($_SESSION);
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit();
    }

    public function redirectHtml(string $url, string $msg = '', int $refreshTime = 0): void
    {
        if (!LanguageStack::initialized() || !TemplateRegistry::isInitialized()) {
            CurrentUser::setRawAttributes(Kernel::service(UserService::class)->buildUser(Config::guestId(), true));
            $this->langService->loadLanguage('common.lang');
            $this->dispatcher->dispatch(new LoadingLang());
            $this->langService->loadLanguage('lang', $this->paths->local, ['no_fallback' => true, 'local' => true]);
            $tpl = new Template($this->paths->root . 'themes', Kernel::service(UserService::class)->getDefaultTheme());
            TemplateRegistry::set($tpl);
        } elseif (RequestContextRegistry::current() === RequestContext::Admin) {
            $tpl = new Template($this->paths->root . 'themes', Kernel::service(UserService::class)->getDefaultTheme());
            TemplateRegistry::set($tpl);
        }

        if (empty($msg)) {
            $msg = nl2br(Lang::t('Redirection...'));
        }

        $refresh  = $refreshTime;
        $url_link = $url;
        $title    = 'redirection';

        $tpl = TemplateRegistry::current();

        PageHeaderRenderer::render($title, $refresh, $url_link);

        $tpl->assign('REDIRECT_MSG', new Html($msg));
        $tpl->parse('redirect.latte');

        PageTailRenderer::render();
        $this->session->persistInto($_SESSION);
        exit();
    }
}
