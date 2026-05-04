<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('albums');
$tabsheet->select($page['tab']);
$tabsheet->assign();

$nb_cats = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)->countAll();
$template->assign(
    [
    'nb_cats' => $nb_cats,
  ]
);
