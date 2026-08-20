<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Event\HelpPageRendered;
use Piwigo\Admin\Projection\HelpView;
use Piwigo\Admin\Request\HelpSectionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;

/**
 * Ported from admin/help.php (page slug "help").
 */
final class HelpPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, Renderer $renderer): void
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
        $tabsheet->assign($currentTemplate, $renderer);

        $eventDispatcher->dispatch(new HelpPageRendered());

        $help_content_raw = $lang->load(
            'help/help_' . $tabsheet->selected . '.html',
            '',
            [
                'return' => true,
            ]
        );

        $adminContent = $renderer->render(new HelpView(
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

        $template->assignContext(new AdminContentPageContext(adminContent: $adminContent));
    }
}
