<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Lang\Translator;

/**
 * Ported from admin/group_list.php (page slug "group_list").
 */
final class GroupListPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('groups');
        $tabsheet->select('group_list');
        $tabsheet->assign();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        if ($_POST !== [] or isset($_GET['delete']) or isset($_GET['toggle_is_default'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new \Piwigo\Html\HtmlService(), $this->redirectService);
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

        $group_repo = new GroupRepository(DbConnection::build());
        $groups = $group_repo->findAllBasic();

        $admin_url = $this->urlService->getRootUrl() . 'admin.php?page=';
        $perm_url = $admin_url . 'group_perm&amp;group_id=';
        $users_url = $admin_url . 'user_list&amp;group=';
        $del_url = $admin_url . 'group_list&amp;delete=';
        $toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';

        $group_counter = 0;

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();

        foreach ($groups as $row) {
            $members = $group_repo->findMemberUsernames($row['id'], $user_fields['username'], $user_fields['id']);

            $template->append(
                'groups',
                [
                    'NAME' => $row['name'],
                    'ID' => $row['id'],
                    'IS_DEFAULT' => ($row['is_default'] ? ' [' . Lang::t('default') . ']' : ''),
                    'NB_MEMBERS' => count($members),
                    'L_MEMBERS' => implode(' <span class="userSeparator">&middot;</span> ', $members),
                    'MEMBERS' => Translator::get()->plural('%d member', '%d members', count($members)),
                    'U_DELETE' => $del_url . $row['id'] . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                    'U_PERM' => $perm_url . $row['id'],
                    'U_USERS' => $users_url . $row['id'],
                    'U_ISDEFAULT' => $toggle_is_default_url . $row['id'] . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                ]
            );

            $group_counter++;
        }

        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'group_list');
    }
}
