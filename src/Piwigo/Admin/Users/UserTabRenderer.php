<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Admin\Tabsheet;

final class UserTabRenderer
{
    public function render(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select(is_string($page['tab'] ?? null) ? $page['tab'] : '');
        $tabsheet->assign();
    }
}
