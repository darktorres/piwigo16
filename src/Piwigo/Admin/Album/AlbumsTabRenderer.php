<?php

declare(strict_types=1);

namespace Piwigo\Admin\Album;

use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\ServiceLocator;
use Piwigo\Template\TemplateRegistry;

final class AlbumsTabRenderer
{
    public function render(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select(is_string($page['tab'] ?? null) ? $page['tab'] : '');
        $tabsheet->assign();
        $nbCats = ServiceLocator::get(CategoryRepository::class)->countAll();
        TemplateRegistry::current()->assign(['nb_cats' => $nbCats]);
    }
}
