<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

final class ConfigurationController
{
    /** @var list<string> */
    public const array PAGES = [
        'configuration',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'configuration' => $this->configuration(),
            default         => null,
        };
    }

    private function configuration(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
        require PHPWG_ROOT_PATH . 'admin/configuration.php';
    }
}
