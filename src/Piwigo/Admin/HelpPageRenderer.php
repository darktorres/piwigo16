<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\HelpPageContext;
use Piwigo\Admin\Request\HelpSectionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Event\Location\LocEndHelp;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Ported from admin/help.php (page slug "help").
 */
final class HelpPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $selected = HelpSectionRequest::fromGlobals()->section;

        // CoreTabs::setContext() must be called with helpLink, or this
        // page's tab strip renders broken relative hrefs.
        $coreTabs->setContext(new CoreTabsContext(helpLink: $urlService->getRootUrl() . 'admin.php?page=help&amp;section='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('help');
        $tabsheet->select($selected, $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $eventDispatcher->dispatchNotify(new LocEndHelp());

        $template->set_filenames([
            'help' => 'help.tpl',
        ]);

        $help_content_raw = $lang->load(
            'help/help_' . $tabsheet->selected . '.html',
            '',
            [
                'return' => true,
            ]
        );

        $template->assignContext(new HelpPageContext(
            helpContent: is_string($help_content_raw) ? $help_content_raw : '',
            helpSectionTitle: $tabsheet->sheets[$tabsheet->selected]->caption,
        ));

        $user_language = $currentUser->get()
            ->language->value;
        $language_prefix = substr($user_language, 0, 3);
        if ($language_prefix === 'en_') {
            $pageState->addMessage(sprintf(
                'Need help to use Piwigo? <a href="%s" target="_blank">Check the online documentation</a> !',
                'https://upstream.example.invalid/help/'
            ));
        } elseif ($language_prefix === 'fr_') {
            $pageState->addMessage(sprintf(
                'Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !',
                'https://upstream.example.invalid/help/fr/'
            ));
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'help');
    }
}
