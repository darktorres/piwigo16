<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

final class GroupsController
{
    /** @var list<string> */
    public const array PAGES = [
        'group_list',
        'group_perm',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'group_list' => $this->groupList(),
            'group_perm' => $this->groupPerm(),
            default      => null,
        };
    }

    private function groupList(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/group_list.php';
    }

    private function groupPerm(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/group_perm.php';
    }
}
