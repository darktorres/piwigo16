<?php

declare(strict_types=1);

namespace Piwigo\Admin;

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
 * (Ws\PwgCore::historySearch()); this page only renders the filter form.
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

        $template->set_filename('history', 'history.tpl');

        $coreTabs->setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('history');
        $tabsheet->select($pageSlug, $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $template->assign(
            [
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php?page=history',
                'API_METHOD' => 'ws.php?format=json&method=pwg.history.search',
            ]
        );

        $form = [];

        // by default, at page load, we want the selected date to be the current
        // date
        $form['start'] = $form['end'] = Env::now()->format('Y-m-d');
        $form['types'] = $types;
        // Hoverbox by default
        $form['display_thumbnail'] =
          new CookieService()
              ->getCookieVar('display_thumbnail', 'no_display_thumbnail');

        $form_param = [];
        $form_param['ip'] = $historyFilter->ip;
        $form_param['image_id'] = $historyFilter->imageId;
        $form_param['user_id'] = $historyFilter->userId;

        if ($historyFilter->hasAnyFilter) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] !== -1) {
            $form_param_user_id = UserId::tryFrom($form_param['user_id']);
            $form_param_username = $form_param_user_id === null ? null : (new UserRepository(EntityManagerFactory::build($conn), $eventDispatcher, $currentConfig))
                ->findUsernameById($form_param_user_id);
            $form_param['user_name'] = $form_param_username?->value;
            $form_param['user_id'] = $form_param['user_name'] === null ? -1 : $form_param['user_id'];
        }

        $template->assign(
            [
                'USER_ID' => $form_param['user_id'],
                'USER_NAME' => $form_param['user_name'] ?? null,
                'IMAGE_ID' => $form_param['image_id'],
                'IP' => $form_param['ip'],
                'START' => $form['start'],
                'END' => $form['end'],
            ]
        );

        $template->assign('display_thumbnails', $display_thumbnails);
        $template->assign('display_thumbnail_selected', $form['display_thumbnail']);
        $template->assign('guest_id', $currentConfig->guestId());
        $template->assign('ADMIN_PAGE_TITLE', $lang->t('History'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'history');
    }
}
