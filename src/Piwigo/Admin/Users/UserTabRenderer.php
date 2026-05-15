<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Admin\Tabsheet;

final class UserTabRenderer
{
    public function render(string $tab = ''): void
    {
        $tabsheet = new Tabsheet();
        $tabsheet->setId('users');
        $tabsheet->select($tab);
        $tabsheet->assign();
    }
}
