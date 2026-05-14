<?php

declare(strict_types=1);

namespace Piwigo\Admin\Album;

use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

final readonly class AlbumsTabRenderer
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private UrlGenerator $urlGenerator,
    ) {
    }
    public function render(string $tab = ''): void
    {
        $GLOBALS['my_base_url'] = $this->urlGenerator->admin() . '&page=';
        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select($tab);
        $tabsheet->assign();
        $nbCats = $this->categoryRepository->countAll();
        TemplateRegistry::current()->assign(['nb_cats' => $nbCats]);
    }
}
