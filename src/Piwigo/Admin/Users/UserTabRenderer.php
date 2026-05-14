<?php

declare(strict_types=1);

namespace Piwigo\Admin\Users;

use Piwigo\Admin\Tabsheet;
use Piwigo\Url\UrlGenerator;

final readonly class UserTabRenderer
{
    public function __construct(
        private UrlGenerator $urlGenerator,
    ) {
    }

    public function render(string $tab = ''): void
    {
        $GLOBALS['my_base_url'] = $this->urlGenerator->admin() . '&page=';
        $tabsheet = new Tabsheet();
        $tabsheet->setId('users');
        $tabsheet->select($tab);
        $tabsheet->assign();
    }
}
