<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\GroupListPageContext;
use Piwigo\Admin\Request\GroupListActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\Projection\GroupListRow;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/group_list.php (page slug "group_list").
 */
final readonly class GroupListPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private Translator $translator,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
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
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $group_repo = $this->entityManager->getRepository(GroupEntity::class);
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

            $tpl_groups[] = new GroupListRow(
                name: $row->name,
                id: $row->id,
                isDefaultLabel: $row->isDefault ? ' [' . $this->lang->t('default') . ']' : '',
                nbMembers: count($members),
                membersList: implode(' <span class="userSeparator">&middot;</span> ', $members),
                membersLabel: $this->translator->plural('%d member', '%d members', count($members)),
                deleteUrl: $del_url . $row->id->value . '&amp;pwg_token=' . $this->csrfService->getToken(),
                permUrl: $perm_url . $row->id->value,
                usersUrl: $users_url . $row->id->value,
                toggleDefaultUrl: $toggle_is_default_url . $row->id->value . '&amp;pwg_token=' . $this->csrfService->getToken(),
            );

            $group_counter++;
        }

        $template->assignContext(new GroupListPageContext(
            addAction: $this->urlService->getRootUrl() . 'admin.php?page=group_list',
            pwgToken: $this->csrfService
                ->getToken(),
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            adminPageTitle: $this->lang->t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>',
            groups: $tpl_groups,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'group_list.latte');
    }
}
