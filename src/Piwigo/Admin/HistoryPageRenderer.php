<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\HistoryPageContext;
use Piwigo\Admin\Request\HistoryFilterRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\History\HistoryImageType;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/history.php (page slug "history") -- displays the
 * filtered history lines panel. The actual line listing is fetched
 * client-side via an async ws.php?method=pwg.history.search call
 * (Ws\Core::historySearch()); this page only renders the filter form.
 */
final class HistoryPageRenderer
{
    /**
     * $pageSlug is an explicit param instead of `global $page['page'];` --
     * the one real caller (HistorySubController) already knows its own
     * fixed page slug statically (it's the only class registered for the
     * 'history' slug in config/admin_pages.php); selects this page's own
     * tab within the shared 'history' tabsheet group (see
     * StatsPageRenderer, its sibling in that same group).
     */
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, CoreTabs $coreTabs, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, InputValidator $inputValidator): void
    {
        $template = $currentTemplate->get();
        $conn = DbConnection::build();

        $types = array_merge(['none'], array_map(
            static fn (HistoryImageType $type): string => $type->value,
            HistoryImageType::cases()
        ));

        $display_thumbnails = [
            'no_display_thumbnail' => $lang->t('No display'),
            'display_thumbnail_classic' => $lang->t('Classic display'),
            'display_thumbnail_hoverbox' => $lang->t('Hoverbox display'),
        ];

        $accessControl->checkStatus(AccessLevel::Administrator);

        $historyFilter = HistoryFilterRequest::fromGlobals($inputValidator);

        $template->setFilename('history', 'history.tpl');

        $coreTabs->setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('history');
        $tabsheet->select($pageSlug, $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $form = [];

        // by default, at page load, we want the selected date to be the current
        // date
        $form['start'] = $form['end'] = Env::now()->format('Y-m-d');
        $form['types'] = $types;
        // Hoverbox by default
        $form['display_thumbnail'] =
          new CookieService()
              ->getDisplayThumbnailPref() ?? 'no_display_thumbnail';

        $form_param = [];
        $form_param['ip'] = $historyFilter->ip;
        $form_param['image_id'] = $historyFilter->imageId;
        $form_param['user_id'] = $historyFilter->userId;

        if ($historyFilter->hasAnyFilter) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] !== -1) {
            $form_param_user_id = UserId::tryFrom($form_param['user_id']);
            $form_param_username = $form_param_user_id instanceof UserId ? new UserRepository(EntityManagerFactory::build($conn), $eventDispatcher, $currentConfig)
                ->findUsernameById($form_param_user_id) : null;
            $form_param['user_name'] = $form_param_username?->value;
            $form_param['user_id'] = $form_param['user_name'] === null ? -1 : $form_param['user_id'];
        }

        $template->assignContext(new HistoryPageContext(
            fAction: $urlService->getRootUrl() . 'admin.php?page=history',
            apiMethod: 'ws.php?format=json&method=pwg.history.search',
            userId: $form_param['user_id'],
            userName: $form_param['user_name'] ?? null,
            imageId: is_string($form_param['image_id']) || is_int($form_param['image_id']) ? (string) $form_param['image_id'] : '',
            ip: is_string($form_param['ip']) || is_int($form_param['ip']) ? (string) $form_param['ip'] : '',
            start: $form['start'],
            end: $form['end'],
            displayThumbnails: $display_thumbnails,
            displayThumbnailSelected: $form['display_thumbnail'],
            guestId: $currentConfig->guestId,
            adminPageTitle: $lang->t('History'),
        ));

        $template->assignVarFromHandle('ADMIN_CONTENT', 'history');
    }
}
