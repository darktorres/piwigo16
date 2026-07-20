<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces popuphelp.php (front-end help popup -- distinct from
 * admin/popuphelp.php, a standalone root-style entry point untouched
 * since P21). check_status() stays outside the captured closure, same
 * exit()-based-termination limitation as every other controller this
 * phase.
 *
 * The `?page=` validation's die() deliberately stays *inside* the closure,
 * matching the legacy file's own real order (page_header.php echoes
 * *before* the validation runs) -- an invalid `?page=` value dies after
 * partial output today, not before it. PHP flushes any still-active output
 * buffer on exit()/die() by default, so wrapping this in ob_start() still
 * reproduces that exact partial-output behavior rather than silently
 * swallowing it.
 */
final class PopuphelpController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;

        $body = LegacyRenderCapture::capture(static function () use ($rawPage): void {
            // Legacy popuphelp.php also did `define('PWG_HELP', true);`
            // here -- confirmed via a project-wide grep that nothing reads
            // that constant anywhere (not even admin/popuphelp.php, which
            // defines the same constant for its own, unrelated reasons);
            // dropped rather than ported, and src/Piwigo/ itself is
            // arch-tested to contain zero define() calls at all
            // (tests/Arch/StructuralTest.php).

            // $title is set and read entirely within this closure (passed
            // straight into PageHeaderRenderer::render() below) -- no
            // other file reads $GLOBALS['title']. Plain local, not global.
            $template = \Piwigo\Template\CurrentTemplate::get();

            \Piwigo\Core\PageState::current()->setBodyId('thePopuphelpPage');
            $title = l10n('Piwigo Help');
            \Piwigo\Core\PageState::current()->setPageBanner('');
            \Piwigo\Core\PageState::current()->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);
            new \Piwigo\Page\PageHeaderRenderer()
                ->render($title);

            if (! is_string($rawPage) || ! (bool) preg_match('/^[a-z_]*$/', $rawPage)) {
                die('Hacking attempt!');
            }

            $help_content = Lang::load('help/' . $rawPage . '.html', '', [
                'return' => true,
            ]);
            if ($help_content === false) {
                $help_content = '';
            }

            $help_content = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                'get_popup_help_content',
                $help_content,
                $rawPage
            );

            $template->set_filename('popuphelp', 'popuphelp.tpl');
            $template->assign([
                'HELP_CONTENT' => $help_content,
            ]);

            $template->pparse('popuphelp');

            \Piwigo\Bootstrap\PageTail::render();
        });

        return ResponseFactory::html($body);
    }
}
