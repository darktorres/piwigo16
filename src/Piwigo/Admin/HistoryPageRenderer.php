<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\HistoryView;
use Piwigo\Admin\Request\HistoryFilterRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/history.php (page slug "history") -- displays the
 * filtered history lines panel. The actual line listing is fetched
 * client-side via `GET /api/v1/history/search`
 * ({@see \Piwigo\Controller\Api\History\HistorySearchController}); this
 * page only renders the filter form.
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
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, CoreTabs $coreTabs, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, InputValidator $inputValidator, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $accessControl->checkStatus(AccessLevel::Administrator);

        $historyFilter = HistoryFilterRequest::fromGlobals($inputValidator);

        $coreTabs->setContext(new CoreTabsContext(linkStart: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('history');
        $tabsheet->select($pageSlug, $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

        $form = [];

        // by default, at page load, we want the selected date to be the current
        // date
        $form['start'] = $form['end'] = Env::now()->format('Y-m-d');

        $form_param = [];
        $form_param['ip'] = $historyFilter->ip;
        $form_param['image_id'] = $historyFilter->imageId;
        $form_param['user_id'] = $historyFilter->userId;

        if ($historyFilter->hasAnyFilter) {
            $form['start'] = '';
        }

        if ($form_param['user_id'] !== -1) {
            $form_param_user_id = UserId::tryFrom($form_param['user_id']);
            $form_param_username = $form_param_user_id instanceof UserId ? new UserRepository($entityManager, $eventDispatcher, $currentConfig)
                ->findUsernameById($form_param_user_id) : null;
            $form_param['user_name'] = $form_param_username?->value;
            $form_param['user_id'] = $form_param['user_name'] === null ? -1 : $form_param['user_id'];
        }

        $jquery_code_raw = $lang->langInfo()['jquery_code'] ?? null;
        $jquery_code = is_string($jquery_code_raw) ? $jquery_code_raw : '';

        $adminContent = $renderer->render(new HistoryView(
            userId: $form_param['user_id'],
            userName: $form_param['user_name'] ?? null,
            imageId: $form_param['image_id'] ?? '',
            ip: $form_param['ip'] ?? '',
            start: $form['start'],
            end: $form['end'],
            guestId: $currentConfig->guestId,
            jqueryCode: $jquery_code,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('History'),
        );
    }
}
