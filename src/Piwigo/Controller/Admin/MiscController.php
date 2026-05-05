<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

final class MiscController
{
    /** @var list<string> */
    public const array PAGES = [
        'notification_by_mail',
        'permalinks',
        'tags',
        'help',
        'popuphelp',
        'intro',
        'menubar',
        'index',
        'comments',
        'rating',
        'rating_user',
        'profile',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'notification_by_mail' => $this->notificationByMail(),
            'permalinks'           => $this->permalinks(),
            'tags'                 => $this->tags(),
            'help'                 => $this->help(),
            'popuphelp'            => $this->popupHelp(),
            'intro'                => $this->intro(),
            'menubar'              => $this->menubar(),
            'index'                => $this->index(),
            'comments'             => $this->comments(),
            'rating'               => $this->rating(),
            'rating_user'          => $this->ratingUser(),
            'profile'              => $this->profile(),
            default                => null,
        };
    }

    private function notificationByMail(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/notification_by_mail.php';
    }

    private function permalinks(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/permalinks.php';
    }

    private function tags(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/tags.php';
    }

    private function help(): void
    {
        require PHPWG_ROOT_PATH . 'admin/help.php';
    }

    private function popupHelp(): void
    {
        require PHPWG_ROOT_PATH . 'admin/popuphelp.php';
    }

    private function intro(): void
    {
        require PHPWG_ROOT_PATH . 'admin/intro.php';
    }

    private function menubar(): void
    {
        require PHPWG_ROOT_PATH . 'admin/menubar.php';
    }

    private function index(): void
    {
        $url = get_root_url();
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit();
    }

    private function comments(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/comments.php';
    }

    private function rating(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/rating.php';
    }

    private function ratingUser(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/rating_user.php';
    }

    private function profile(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/profile.php';
    }
}
