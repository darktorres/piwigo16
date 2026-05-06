<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Admin\Tabsheet;
use Piwigo\Core\ServiceLocator;
use Piwigo\Url\UrlGenerator;

final class UserTabRenderer
{
    public function render(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $GLOBALS['my_base_url'] = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select(is_string($page['tab'] ?? null) ? $page['tab'] : '');
        $tabsheet->assign();
    }
}
