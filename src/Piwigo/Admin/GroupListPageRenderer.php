<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Lang\Translator;

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
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // myBaseUrl for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered a broken relative href.
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('groups');
        $tabsheet->select('group_list');
        $tabsheet->assign($this->currentTemplate);

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        if (Request\GroupListActionRequest::fromGlobals()->requiresCsrfCheck) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $template->set_filenames([
            'group_list' => 'group_list.tpl',
        ]);

        $template->assign(
            [
                'F_ADD_ACTION' => $this->urlService->getRootUrl() . 'admin.php?page=group_list',
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            ]
        );

        $group_repo = \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Group\GroupEntity::class);
        $groups = $group_repo->findAllBasic();

        $admin_url = $this->urlService->getRootUrl() . 'admin.php?page=';
        $perm_url = $admin_url . 'group_perm&amp;group_id=';
        $users_url = $admin_url . 'user_list&amp;group=';
        $del_url = $admin_url . 'group_list&amp;delete=';
        $toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';

        $group_counter = 0;

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        foreach ($groups as $row) {
            $members = $group_repo->findMemberUsernames($row->id, $user_fields['username'], $user_fields['id']);

            $template->append(
                'groups',
                [
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
                    'U_DELETE' => $del_url . $row->id->value . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                    'U_PERM' => $perm_url . $row->id->value,
                    'U_USERS' => $users_url . $row->id->value,
                    'U_ISDEFAULT' => $toggle_is_default_url . $row->id->value . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                ]
            );

            $group_counter++;
        }

        $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'group_list');
    }
}
