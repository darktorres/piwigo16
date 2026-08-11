<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\GroupListPageContext;
use Piwigo\Admin\Request\GroupListActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/group_list.php (page slug "group_list").
 */
final class GroupListPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly Translator $translator,
        private readonly CurrentTemplate $currentTemplate,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentConfig $currentConfig,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('groups');
        $tabsheet->select('group_list', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        if (GroupListActionRequest::fromGlobals()->requiresCsrfCheck) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $template->setFilenames([
            'group_list' => 'group_list.tpl',
        ]);

        $group_repo = EntityManagerFactory::build(DbConnection::build())->getRepository(GroupEntity::class);
        $groups = $group_repo->findAllBasic();

        $admin_url = $this->urlService->getRootUrl() . 'admin.php?page=';
        $perm_url = $admin_url . 'group_perm&amp;group_id=';
        $users_url = $admin_url . 'user_list&amp;group=';
        $del_url = $admin_url . 'group_list&amp;delete=';
        $toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';

        $group_counter = 0;
        $tpl_groups = [];

        foreach ($groups as $row) {
            $members = $group_repo->findMemberUsernames($row->id);

            $tpl_groups[] = [
                'NAME' => $row->name,
                // Explicit ->value, not relying on GroupId's Stringable
                // -- Smarty templates elsewhere in this page do real
                // arithmetic on ID (group_list.tpl's `$group.ID%5`),
                // which would TypeError against a bare VO object.
                'ID' => $row->id->value,
                'IS_DEFAULT' => ($row->isDefault ? ' [' . $this->lang->t('default') . ']' : ''),
                'NB_MEMBERS' => count($members),
                'L_MEMBERS' => implode(' <span class="userSeparator">&middot;</span> ', $members),
                'MEMBERS' => $this->translator->plural('%d member', '%d members', count($members)),
                'U_DELETE' => $del_url . $row->id->value . '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
                'U_PERM' => $perm_url . $row->id->value,
                'U_USERS' => $users_url . $row->id->value,
                'U_ISDEFAULT' => $toggle_is_default_url . $row->id->value . '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
            ];

            $group_counter++;
        }

        $template->assignContext(new GroupListPageContext(
            addAction: $this->urlService->getRootUrl() . 'admin.php?page=group_list',
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            adminPageTitle: $this->lang->t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>',
            groups: $tpl_groups,
        ));

        $template->assignVarFromHandle('ADMIN_CONTENT', 'group_list');
    }
}
