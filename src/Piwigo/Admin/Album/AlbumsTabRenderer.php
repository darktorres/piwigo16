<?php

declare(strict_types=1);

namespace Piwigo\Admin\Album;

use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\ServiceLocator;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

final class AlbumsTabRenderer
{
    public function render(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $GLOBALS['my_base_url'] = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select(is_string($page['tab'] ?? null) ? $page['tab'] : '');
        $tabsheet->assign();
        $nbCats = ServiceLocator::get(CategoryRepository::class)->countAll();
        TemplateRegistry::current()->assign(['nb_cats' => $nbCats]);
    }
}
