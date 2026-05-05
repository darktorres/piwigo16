<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

final class UsersController
{
    /** @var list<string> */
    public const array PAGES = [
        'user_list',
        'user_perm',
        'user_activity',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'user_list'     => $this->userList(),
            'user_perm'     => $this->userPerm(),
            'user_activity' => $this->userActivity(),
            default         => null,
        };
    }

    private function userList(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/user_list.php';
    }

    private function userPerm(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/user_perm.php';
    }

    private function userActivity(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/user_activity.php';
    }
}
