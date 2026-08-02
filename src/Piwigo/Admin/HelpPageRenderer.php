<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocEndHelp;

/**
 * Ported from admin/help.php (page slug "help").
 */
final class HelpPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $selected = Request\HelpSectionRequest::fromGlobals()->section;

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // helpLink for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered broken relative hrefs.
        CoreTabs::setContext(new CoreTabsContext(helpLink: $urlService->getRootUrl() . 'admin.php?page=help&amp;section='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('help');
        $tabsheet->select($selected);
        $tabsheet->assign();

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocEndHelp());

        $template->set_filenames([
            'help' => 'help.tpl',
        ]);

        $template->assign(
            [
                'HELP_CONTENT' => Lang::load(
                    'help/help_' . $tabsheet->selected . '.html',
                    '',
                    [
                        'return' => true,
                    ]
                ),
                'HELP_SECTION_TITLE' => $tabsheet->sheets[$tabsheet->selected]['caption'],
            ]
        );

        $user_language = \Piwigo\Users\CurrentUser::get()->language;
        $language_prefix = substr($user_language, 0, 3);
        if ($language_prefix === 'en_') {
            \Piwigo\Core\PageState::current()->addMessage(sprintf(
                'Need help to use Piwigo? <a href="%s" target="_blank">Check the online documentation</a> !',
                'https://upstream.example.invalid/help/'
            ));
        } elseif ($language_prefix === 'fr_') {
            \Piwigo\Core\PageState::current()->addMessage(sprintf(
                'Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !',
                'https://upstream.example.invalid/help/fr/'
            ));
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'help');
    }
}
