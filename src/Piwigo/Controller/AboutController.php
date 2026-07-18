<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Menu\MenubarRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces about.php -- P22's own proof-of-concept controller (smallest,
 * lowest-risk real page: no POST handling, no redirects). Its legacy body
 * is unchanged, wrapped in LegacyRenderCapture instead of echoing directly.
 *
 * check_status() stays outside the captured closure: it can call
 * access_denied() (-> exit()) directly on failure, same accepted
 * exit()-based-termination limitation as every other legacy redirect/
 * denial path this phase (see docs/plan/manifest.yaml's P22 entry) --
 * letting that exit() escape an active output buffer would risk PHP's
 * shutdown-time auto-flush interacting unpredictably with headers already
 * sent by access_denied() itself.
 */
final class AboutController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $body = LegacyRenderCapture::capture(static function (): void {
            // Bootstrap globals, set by include/common.inc.php; reads and
            // writes of them need an explicit `global` here since this
            // closure is not real global scope (same lesson as P21's
            // AdminDispatcher scope bug).
            /**
             * @var array<string, mixed>
             */
            global $conf;
            /**
             * @var array<string, mixed>
             */
            global $page;
            global $title;
            $template = \Piwigo\Template\CurrentTemplate::get();

            $title = l10n('About Piwigo');
            $page['body_id'] = 'theAboutPage';

            trigger_notify('loc_begin_about');

            $template->set_filename('about', 'about.tpl');

            $template->assign('ABOUT_MESSAGE', Lang::load('about.html', '', [
                'return' => true,
            ]));

            $user_theme = \Piwigo\Users\CurrentUser::get()->theme;

            $theme_about = Lang::load('about.html', Config::themesPath() . $user_theme . '/', [
                'return' => true,
            ]);
            if ($theme_about !== false) {
                $template->assign('THEME_ABOUT', $theme_about);
            }

            $themeconf = $template->get_template_vars('themeconf');
            $themeconf = is_array($themeconf) ? $themeconf : [];
            if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theAboutPage', $themeconf['hide_menu_on'], true)) {
                new MenubarRenderer()
                    ->render();
            }

            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);
            new HtmlService()
                ->flushPageMessages();
            $template->pparse('about');
            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}
