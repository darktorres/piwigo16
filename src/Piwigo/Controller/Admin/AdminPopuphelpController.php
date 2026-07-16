<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Controller\LegacyRenderCapture;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Template\Template;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/popuphelp.php -- the admin-context help popup, distinct
 * from Piwigo\Controller\PopuphelpController (the front-end one, its own
 * docblock already flags this file as "a standalone root-style entry point
 * untouched since P21"). Not a `?page=` slug -- reached as its own real
 * file path (`/admin/popuphelp.php`), invoked from the admin UI's
 * `popuphelp(url)` JS popup-window helper (themes/default/js/scripts.js),
 * so it gets its own config/routes.php entry rather than a
 * config/admin_pages.php one.
 *
 * `PWG_HELP` dropped the same way the front-end port already dropped it
 * (confirmed via a project-wide grep: nothing reads that constant anywhere,
 * not even this file's own original body). `IN_ADMIN` is genuinely read
 * elsewhere (Piwigo\Page\PageHeaderRenderer) so it stays, set by the new
 * bootstrap file the same way admin.php itself sets it.
 *
 * check_status() stays outside the captured closure, same exit()-based-
 * termination limitation as every other controller this phase. The
 * `?page=` validation's die() and the `output=content_only` branch's
 * echo+exit() both deliberately stay *inside* the closure, matching the
 * legacy file's own real order and PHP's documented output-buffer-flush-
 * on-exit() behavior -- same reasoning already established in
 * PopuphelpController's own docblock for its analogous die() case.
 */
final class AdminPopuphelpController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $queryParams = $request->getQueryParams();
        $rawPage = $queryParams['page'] ?? null;
        $output = $queryParams['output'] ?? null;

        $body = LegacyRenderCapture::capture(static function () use ($rawPage, $output): void {
            /**
             * @var array<string, mixed> $page
             * @var Template $template
             */
            global $page, $template, $title;

            if ($output !== 'content_only') {
                $page['body_id'] = 'thePopuphelpPage';
                $title = l10n('Piwigo Help');
                $page['page_banner'] = '<h1>' . $title . '</h1>';
                $page['meta_robots'] = [
                    'noindex' => 1,
                    'nofollow' => 1,
                ];

                // set required template variables to avoid "Undefined array key" with PHP 8
                $template->assign(
                    [
                        'U_RETURN' => '',
                        'USERNAME' => '',
                        'U_FAQ' => '',
                        'U_CHANGE_THEME' => '',
                        'U_LOGOUT' => '',
                    ]
                );

                include PHPWG_ROOT_PATH . 'include/page_header.php';
            }

            if (! is_string($rawPage) || ! (bool) preg_match('/^[a-z_]*$/', $rawPage)) {
                die('Hacking attempt!');
            }

            $help_content = Lang::load(
                'help/' . $rawPage . '.html',
                '',
                [
                    'force_fallback' => 'en_UK',
                    'return' => true,
                ]
            );
            if ($help_content === false) {
                $help_content = '';
            }

            $help_content = trigger_change('get_popup_help_content', $help_content, $rawPage);
            if (! is_string($help_content)) {
                $help_content = '';
            }

            $template->set_filename('popuphelp', 'popuphelp.tpl');
            $template->assign(
                [
                    'HELP_CONTENT' => $help_content,
                ]
            );

            if ($output === 'content_only') {
                echo $help_content;
                exit();
            }

            $template->pparse('popuphelp');

            include PHPWG_ROOT_PATH . 'include/page_tail.php';
        });

        return ResponseFactory::html($body);
    }
}
