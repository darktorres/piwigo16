<?php

declare(strict_types=1);

namespace Piwigo\Admin\Album;

use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Template\TemplateRegistry;

final readonly class AlbumsTabRenderer
{
    public function __construct(
        private CategoryRepository $categoryRepository,
    ) {
    }
    public function render(string $tab = ''): void
    {
        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select($tab);
        $tabsheet->assign();
        $nbCats = $this->categoryRepository->countAll();
        TemplateRegistry::current()->assign(['nb_cats' => $nbCats]);
    }
}
