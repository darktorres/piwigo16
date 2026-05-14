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
    public function render(): void
    {
        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $GLOBALS['my_base_url'] = $this->urlGenerator->admin() . '&page=';
        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select(is_string($page['tab'] ?? null) ? $page['tab'] : '');
        $tabsheet->assign();
        $nbCats = $this->categoryRepository->countAll();
        TemplateRegistry::current()->assign(['nb_cats' => $nbCats]);
    }
}
