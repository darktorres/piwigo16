<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Template\TemplateRegistry;

final class PhotoController
{
    /** @var list<string> */
    public const array PAGES = [
        'photo',
        'picture_modify',
        'picture_coi',
        'picture_formats',
        'photos_add',
        'photos_add_direct',
        'photos_add_ftp',
        'photos_add_applications',
    ];

    public function handle(string $page): void
    {
        match ($page) {
            'photo'                   => $this->photo(),
            'picture_modify'          => $this->pictureModify(),
            'picture_coi'             => $this->pictureCoi(),
            'picture_formats'         => $this->pictureFormats(),
            'photos_add'              => $this->photosAdd(),
            'photos_add_direct'       => $this->photosAddDirect(),
            'photos_add_ftp'          => $this->photosAddFtp(),
            'photos_add_applications' => $this->photosAddApplications(),
            default                   => null,
        };
    }

    private function photo(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
        check_input_parameter('image_id', $_GET, false, PATTERN_ID);

        $image_id_str = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '';
        $admin_photo_base_url = get_root_url() . 'admin.php?page=photo-' . $image_id_str;

        $page['image'] = get_image_infos($image_id_str, true);

        if (isset($_GET['cat_id'])) {
            $cat_id_val = $_GET['cat_id'];
            $category = ServiceLocator::get(CategoryRepository::class)
                ->findCategoryById(is_scalar($cat_id_val) ? (int) $cat_id_val : 0);
            $GLOBALS['category'] = $category;
        }

        $page['tab'] = 'properties';

        if (isset($_GET['tab'])) {
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'properties';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('photo');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tpl->assign([
            'ADMIN_PAGE_TITLE' => l10n('Edit photo') . ' <span class="image-id">#' . $image_id_str . '</span>',
        ]);

        $GLOBALS['admin_photo_base_url'] = $admin_photo_base_url;

        if ('properties' == $page['tab']) {
            $this->pictureModify();
        } elseif ('coi' == $page['tab']) {
            $this->pictureCoi();
        } elseif ('formats' == $page['tab'] && Config::isFormatsEnabled()) {
            $this->pictureFormats();
        } else {
            require PHPWG_ROOT_PATH . 'admin/photo_' . $page['tab'] . '.php';
        }
    }

    private function pictureModify(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require PHPWG_ROOT_PATH . 'admin/picture_modify.php';
    }

    private function pictureCoi(): void
    {
        require PHPWG_ROOT_PATH . 'admin/picture_coi.php';
    }

    private function pictureFormats(): void
    {
        require PHPWG_ROOT_PATH . 'admin/picture_formats.php';
    }

    private function photosAdd(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';

        if (!defined('PHOTOS_ADD_BASE_URL')) {
            define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
        }

        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        check_status(ACCESS_ADMINISTRATOR);

        $upload_form_config = get_upload_form_config();
        $GLOBALS['upload_form_config'] = $upload_form_config;

        if (isset($_GET['section'])) {
            $page['tab'] = is_string($_GET['section']) ? $_GET['section'] : 'direct';
            if ('ploader' == $page['tab']) {
                $page['tab'] = 'applications';
            }
        } else {
            $page['tab'] = 'direct';
        }

        $tabsheet = new \Piwigo\Admin\Tabsheet();
        $tabsheet->set_id('photos_add');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tpl->set_filenames([
            'photos_add' => 'photos_add_' . $page['tab'] . '.tpl',
        ]);

        match ($page['tab']) {
            'direct'       => $this->photosAddDirect(),
            'ftp'          => $this->photosAddFtp(),
            'applications' => $this->photosAddApplications(),
            default        => require PHPWG_ROOT_PATH . 'admin/photos_add_' . $page['tab'] . '.php',
        };
    }

    private function photosAddDirect(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
        if (!defined('PHOTOS_ADD_BASE_URL')) {
            define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
        }
        require PHPWG_ROOT_PATH . 'admin/photos_add_direct.php';
    }

    private function photosAddFtp(): void
    {
        if (!defined('PHOTOS_ADD_BASE_URL')) {
            define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
        }
        require PHPWG_ROOT_PATH . 'admin/photos_add_ftp.php';
    }

    private function photosAddApplications(): void
    {
        if (!defined('PHOTOS_ADD_BASE_URL')) {
            define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
        }
        require PHPWG_ROOT_PATH . 'admin/photos_add_applications.php';
    }

}
