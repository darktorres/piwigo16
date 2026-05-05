<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

final class BatchManagerController
{
    /** @var list<string> */
    public const array PAGES = [
        'batch_manager',
        'batch_manager_global',
        'batch_manager_unit',
        'queue',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'batch_manager'        => $this->batchManager(),
            'batch_manager_global' => $this->batchManagerGlobal(),
            'batch_manager_unit'   => $this->batchManagerUnit(),
            'queue'                => $this->queue(),
            default                => null,
        };
    }

    private function batchManager(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/batch_manager.php';
    }

    private function batchManagerGlobal(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/batch_manager_global.php';
    }

    private function batchManagerUnit(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/batch_manager_unit.php';
    }

    private function queue(): void
    {
        require PHPWG_ROOT_PATH . 'admin/queue.php';
    }
}
