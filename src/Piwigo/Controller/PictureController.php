<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\NotFoundException;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Section\SectionInitializer;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the single-image page (/picture/{rest}).
 * Corresponds to the former picture.php entry-point.
 */
final class PictureController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        require_once PHPWG_ROOT_PATH . 'include/picture_functions.php';

        ServiceLocator::get(SectionInitializer::class)->initialize($request, 'picture');

        save_edit_context();
        check_status(ACCESS_GUEST);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];
        /** @var array<string, mixed> $lang */
        $lang = &$GLOBALS['lang'];

        // Typed locals extracted from $page after SectionInitializer has populated it
        $category  = is_array($page['category'] ?? null) ? $page['category'] : null;
        $catId     = $category !== null && is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
        $rawItems  = is_array($page['items'] ?? null) ? $page['items'] : [];
        $items     = array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $rawItems);
        $imageId   = is_scalar($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;

        if ($category !== null) {
            check_restrictions($catId);
        }

        $page['rank_of'] = array_flip($items);

        if (!isset($page['rank_of'][$imageId])) {
            $imageRepo = ServiceLocator::get(ImageRepository::class);
            if ($imageId > 0) {
                $row = $imageRepo->findById($imageId);
            } else {
                assert(!empty($page['image_file']));
                $pattern = str_replace(['_', '%'], ['/_', '/%'], is_string($page['image_file']) ? $page['image_file'] : '') . '.%';
                $row     = $imageRepo->findByFilePattern($pattern);
            }
            if ($row === null) {
                page_not_found('The requested image does not exist', duplicate_index_url());
                return ResponseFactory::create(404);
            }
            if (is_numeric($row['level'] ?? null) && is_numeric($user['level'] ?? null) && $row['level'] > $user['level']) {
                access_denied();
            }
            $page['image_id']   = $row['id'];
            $page['image_file'] = $row['file'];
            $imageId = is_scalar($row['id'] ?? null) ? (int) $row['id'] : 0;

            if (!isset($page['rank_of'][$imageId])) {
                $filter          = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
                $visibleImages   = is_scalar($filter['visible_images'] ?? null) ? (string) $filter['visible_images'] : '';
                if ($visibleImages !== '' && !in_array($imageId, explode(',', $visibleImages))) {
                    page_not_found('The requested image is filtered', duplicate_index_url());
                    return ResponseFactory::create(404);
                }
                if ('categories' == $page['section'] && !isset($page['category'])) {
                    access_denied();
                } else {
                    $query = '
SELECT id
  FROM ' . IMAGES_TABLE . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=image_id
  WHERE id=' . $imageId
                        . get_sql_condition_FandF(['forbidden_categories' => 'category_id'], ' AND') . '
  LIMIT 1';
                    if (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne() === false) {
                        access_denied();
                    } else {
                        if ('best_rated' == $page['section']) {
                            $page['rank_of'][$imageId] = count($items);
                            $items[]                   = $imageId;
                            $page['items']             = $items;
                        } else {
                            $url = make_picture_url(['image_id' => $imageId, 'image_file' => is_scalar($page['image_file'] ?? null) ? $page['image_file'] : '', 'section' => 'categories', 'flat' => true]);
                            set_status_header('recent_pics' == $page['section'] ? 301 : 302);
                            redirect_http($url);
                        }
                    }
                }
            }
        }

        if (input_string('metadata', null, $_GET) !== null) {
            if (pwg_get_session_var('show_metadata') == null) {
                pwg_set_session_var('show_metadata', 1);
            } else {
                pwg_unset_session_var('show_metadata');
            }
        }

        add_event_handler('render_element_content', 'default_picture_content');
        add_event_handler('render_element_description', 'pwg_nl2br');

        trigger_notify('loc_begin_picture');

        $tpl = TemplateRegistry::current();

        // Refresh typed locals in case image_id was updated in the block above
        $items       = array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, is_array($page['items'] ?? null) ? $page['items'] : []);
        $imageId     = is_scalar($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;
        $nbImagePage = is_scalar($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0;

        $page['first_rank']   = 0;
        $page['last_rank']    = count($items) - 1;
        $page['current_rank'] = $page['rank_of'][$imageId];
        $page['current_item'] = $imageId;

        $currentRank = (int) $page['current_rank'];
        $firstRank   = 0;
        $lastRank    = count($items) - 1;

        if ($currentRank != $firstRank) {
            $page['previous_item'] = $items[$currentRank - 1];
            $page['first_item']    = $items[$firstRank];
        }
        if ($currentRank != $lastRank) {
            $page['next_item'] = $items[$currentRank + 1];
            $page['last_item'] = $items[$lastRank];
        }

        $url_up = duplicate_index_url(
            ['start' => (int) floor($currentRank / $nbImagePage) * $nbImagePage],
            ['start']
        );
        $url_self = duplicate_picture_url();

        // Actions
        $get_action = input_string('action', null, $_GET);
        if ($get_action !== null) {
            switch ($get_action) {
                case 'add_to_favorites':
                    ServiceLocator::get(UserRepository::class)->addFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    redirect($url_self);
                    break;
                case 'remove_from_favorites':
                    ServiceLocator::get(UserRepository::class)->deleteFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    redirect('favorites' == $page['section'] ? $url_up : $url_self);
                    break;
                case 'set_as_representative':
                    if (is_admin() && $category !== null) {
                        ServiceLocator::get(CategoryRepository::class)->setRepresentativePicture([$catId], $imageId);
                        pwg_activity('album', $catId, 'edit', ['action' => $get_action, 'image_id' => $imageId]);
                        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
                        invalidate_user_cache();
                    }
                    redirect($url_self);
                    break;
                case 'add_to_caddie':
                    fill_caddie([$imageId]);
                    redirect($url_self);
                    break;
                case 'rate':
                    rate_picture($imageId, input_int('rate', 0, $_POST));
                    redirect($url_self);
                    break;
                case 'edit_comment':
                    check_input_parameter('comment_to_edit', $_GET, false, PATTERN_ID);
                    $comment_to_edit = input_int('comment_to_edit', null, $_GET);
                    $author_id       = get_comment_author_id($comment_to_edit ?? 0);
                    if (can_manage_comment('edit', (int) $author_id)) {
                        $post_content = input_string('content', null, $_POST);
                        if (!empty($post_content)) {
                            check_pwg_token();
                            $comment_action = update_user_comment(
                                ['comment_id' => $comment_to_edit, 'image_id' => $imageId, 'content' => $post_content, 'website_url' => input_string('website_url', null, $_POST)],
                                input_string('key', null, $_POST) ?? ''
                            );
                            $perform_redirect = false;
                            switch ($comment_action) {
                                case 'moderate':
                                    PageState::current()->addInfo(l10n('An administrator must authorize your comment before it is visible.'));
                                    // no break
                                case 'validate':
                                    PageState::current()->addInfo(l10n('Your comment has been registered'));
                                    $perform_redirect = true;
                                    break;
                                case 'reject':
                                    PageState::current()->addError(l10n('Your comment has NOT been registered because it did not pass the validation rules'));
                                    break;
                                default:
                                    trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                            }
                            if ($perform_redirect) {
                                redirect($url_self);
                            }
                            unset($_POST['content']);
                        }
                        $edit_comment = $comment_to_edit;
                    }
                    break;
                case 'delete_comment':
                    check_pwg_token();
                    check_input_parameter('comment_to_delete', $_GET, false, PATTERN_ID);
                    $author_id = get_comment_author_id(input_int('comment_to_delete', null, $_GET) ?? 0);
                    if (can_manage_comment('delete', (int) $author_id)) {
                        delete_user_comment(input_int('comment_to_delete', null, $_GET) ?? 0);
                    }
                    redirect($url_self);
                    break;
                case 'validate_comment':
                    check_pwg_token();
                    check_input_parameter('comment_to_validate', $_GET, false, PATTERN_ID);
                    $author_id = get_comment_author_id(input_int('comment_to_validate', null, $_GET) ?? 0);
                    if (can_manage_comment('validate', (int) $author_id)) {
                        validate_user_comment(input_int('comment_to_validate', null, $_GET) ?? 0);
                    }
                    redirect($url_self);
                    break;
            }
        }

        // Hit counter
        $inc_hit_count = input_string('content', null, $_POST) === null;
        if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch') {
            $inc_hit_count = false;
        } else {
            if (pwg_get_session_var('referer_image_id', 0) == $imageId) {
                $inc_hit_count = false;
            }
            pwg_set_session_var('referer_image_id', $imageId);
        }
        if (trigger_change('allow_increment_element_hit_count', $inc_hit_count, $imageId)) {
            increase_image_visit_counter($imageId);
        }

        // Related categories
        $query = '
SELECT id,uppercats,commentable,visible,status,global_rank
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . CATEGORIES_TABLE . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . get_sql_condition_FandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'AND') . '
;';
        $related_categories = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        usort($related_categories, global_rank_compare(...));

        // Load prev/current/next picture data
        $picture = [];
        $ids     = [$imageId];
        if (isset($page['previous_item'])) {
            $ids[] = $page['previous_item'];
            $ids[] = $page['first_item'];
        }
        if (isset($page['next_item'])) {
            $ids[] = $page['next_item'];
            $ids[] = $page['last_item'];
        }

        foreach (ServiceLocator::get(ImageRepository::class)->findByIds(array_map(static fn (mixed $i): int => is_scalar($i) ? (int) $i : 0, $ids)) as $row) {
            if (isset($page['previous_item']) && $row['id'] == $page['previous_item']) {
                $i = 'previous';
            } elseif (isset($page['next_item']) && $row['id'] == $page['next_item']) {
                $i = 'next';
            } elseif (isset($page['first_item']) && $row['id'] == $page['first_item']) {
                $i = 'first';
            } elseif (isset($page['last_item']) && $row['id'] == $page['last_item']) {
                $i = 'last';
            } else {
                $i = 'current';
            }

            $src_id   = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $src_path = is_scalar($row['path'] ?? null) ? (string) $row['path'] : '';
            $src_file = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';

            $row['src_image']  = new SrcImage($row);
            $row['derivatives'] = DerivativeImage::get_all($row['src_image']);
            $row['path_ext']   = strtolower(get_extension($src_path));
            $row['file_ext']   = strtolower(get_extension($src_file));

            if ($i == 'current') {
                $row['element_path'] = get_element_path($row);
                if ($row['src_image']->is_original()) {
                    if ($user['enabled_high'] == 'true') {
                        $row['element_url']  = $row['src_image']->get_url();
                        $row['download_url'] = get_action_url($src_id, 'e', true);
                    }
                } else {
                    $row['element_url']  = get_element_url($row);
                    $row['download_url'] = get_action_url($src_id, 'e', true);
                }
            }

            $row['url'] = duplicate_picture_url(['image_id' => $row['id'], 'image_file' => $row['file']], ['start']);
            $row['TITLE']     = render_element_name($row);
            $row['TITLE_ESC'] = str_replace('"', '&quot;', $row['TITLE']);
            $picture[$i]      = $row;

            if ('previous' == $i && $page['previous_item'] == $page['first_item']) {
                $picture['first'] = $row;
            }
            if ('next' == $i && $page['next_item'] == $page['last_item']) {
                $picture['last'] = $row;
            }
        }

        if (!isset($picture['current'])) {
            throw new NotFoundException('Current picture not found.');
        }

        $slideshow_params     = [];
        $slideshow_url_params = [];
        $get_slideshow        = input_string('slideshow', null, $_GET);

        if ($get_slideshow !== null) {
            $page['slideshow']    = true;
            $page['meta_robots']  = ['noindex' => 1, 'nofollow' => 1];
            $slideshow_params     = decode_slideshow_params($get_slideshow);
            $slideshow_url_params['slideshow'] = encode_slideshow_params($slideshow_params);

            if ($slideshow_params['play']) {
                $id_pict_redirect = '';
                if (isset($page['next_item'])) {
                    $id_pict_redirect = 'next';
                } elseif ($slideshow_params['repeat'] && isset($page['first_item'])) {
                    $id_pict_redirect = 'first';
                }
                if (!empty($id_pict_redirect) && isset($picture[$id_pict_redirect])) {
                    $refresh  = $slideshow_params['period'];
                    $url_link = add_url_params((string) $picture[$id_pict_redirect]['url'], $slideshow_url_params);
                }
            }
        } else {
            $page['slideshow'] = false;
        }

        if ($page['slideshow'] && Config::lightSlideshow()) {
            $tpl->set_filenames(['slideshow' => 'slideshow.tpl']);
        } else {
            $tpl->set_filenames(['picture' => 'picture.tpl']);
        }

        $title    = (string) $picture['current']['TITLE'];
        $title_nb = ($currentRank + 1) . '/' . count($items);

        $url_metadata     = duplicate_picture_url();
        $url_metadata     = add_url_params($url_metadata, ['metadata' => null]);
        $curSrcImg = $picture['current']['src_image'];
        $metadata_showable = trigger_change(
            'get_element_metadata_available',
            (Config::showExif() || Config::showIptc()) && !$curSrcImg->is_mimetype(),
            $picture['current']
        );

        if (input_string('metadata', null, $_GET) !== null) {
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
        }

        $page['body_id'] = 'thePicturePage';

        /** @var array<string, array<string, mixed>> $picture */
        $picture    = trigger_change('picture_pictures_data', $picture);
        $currentPic = is_array($picture['current'] ?? null) ? $picture['current'] : [];
        $currentSrcImage = ($currentPic['src_image'] ?? null) instanceof SrcImage ? $currentPic['src_image'] : null;

        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $imgArr = $picture[$which_image];
                $tpl->assign($which_image, array_merge($imgArr, ['U_IMG' => add_url_params(is_string($imgArr['url'] ?? null) ? $imgArr['url'] : '', $slideshow_url_params)]));
            }
        }

        if (Config::pictureDownloadIcon() && !empty($picture['current']['download_url']) && $user['enabled_high'] == 'true') {
            $tpl->append('current', ['U_DOWNLOAD' => $picture['current']['download_url']], true);

            if (Config::isFormatsEnabled()) {
                $query = '
SELECT *
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE image_id = ' . (is_scalar($currentPic['id'] ?? null) ? (int) $currentPic['id'] : 0) . '
;';
                $formats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                array_unshift($formats, [
                    'download_url' => is_scalar($currentPic['download_url'] ?? null) ? $currentPic['download_url'] : '',
                    'ext'          => get_extension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : ''),
                    'filesize'     => $currentPic['filesize'] ?? null,
                ]);
                foreach ($formats as &$format) {
                    if (!isset($format['download_url'])) {
                        $format['download_url'] = ServiceLocator::get(UrlGenerator::class)->actionFormat((int) (is_scalar($format['format_id'] ?? null) ? $format['format_id'] : 0));
                    }
                    $extStr           = is_scalar($format['ext'] ?? null) ? (string) $format['ext'] : '';
                    $format['label']  = strtoupper($extStr);
                    $lang_key         = 'format ' . strtoupper($extStr);
                    if (isset($lang[$lang_key])) {
                        $format['label'] = $lang[$lang_key];
                    }
                    $fsRaw                = $format['filesize'] ?? 0;
                    $format['filesize']   = sprintf('%.1fMB', (is_numeric($fsRaw) ? $fsRaw : 0) / 1024);
                }
                $tpl->append('current', ['formats' => $formats], true);
            }
        }

        // Slideshow controls
        if ($page['slideshow']) {
            $tpl_slideshow = [];
            $currentUrl    = is_string($picture['current']['url'] ?? null) ? $picture['current']['url'] : '';
            $tpl->assign(['U_SLIDESHOW_STOP' => $currentUrl]);
            foreach (['repeat', 'play'] as $p) {
                $var_name = 'U_' . ($slideshow_params[$p] ? 'STOP_' : 'START_') . strtoupper($p);
                $tpl_slideshow[$var_name] = add_url_params($currentUrl, ['slideshow' => encode_slideshow_params(array_merge($slideshow_params, [$p => !$slideshow_params[$p]]))]);
            }
            foreach (['dec', 'inc'] as $op) {
                $periodRaw = $slideshow_params['period'] ?? 0;
                $new_period  = (is_numeric($periodRaw) ? $periodRaw : 0) + (($op == 'dec' ? -1 : 1) * Config::slideshowPeriodStep());
                $new_params  = correct_slideshow_params(array_merge($slideshow_params, ['period' => $new_period]));
                if ($new_params['period'] === $new_period) {
                    $tpl_slideshow['U_' . strtoupper($op) . '_PERIOD'] = add_url_params($currentUrl, ['slideshow' => encode_slideshow_params($new_params)]);
                }
            }
            $tpl->assign('slideshow', $tpl_slideshow);
        } elseif (Config::pictureSlideShowIcon()) {
            $currentUrl = is_string($picture['current']['url'] ?? null) ? $picture['current']['url'] : '';
            $tpl->assign(['U_SLIDESHOW_START' => add_url_params($currentUrl, ['slideshow' => ''])]);
        }

        $tpl->assign([
            'SECTION_TITLE'        => $page['section_title'],
            'PHOTO'                => $title_nb,
            'IS_HOME'              => ('categories' == $page['section'] && !isset($page['category'])),
            'LEVEL_SEPARATOR'      => Config::levelSeparator(),
            'U_UP'                 => $url_up,
            'DISPLAY_NAV_BUTTONS'  => Config::pictureNavigationIcons(),
            'DISPLAY_NAV_THUMB'    => Config::pictureNavigationThumb(),
        ]);

        if (Config::pictureMetadataIcon()) {
            $tpl->assign('U_METADATA', $url_metadata);
        }

        if (is_admin()) {
            if (isset($page['category']) && Config::pictureRepresentativeIcon()) {
                $tpl->assign(['U_SET_AS_REPRESENTATIVE' => add_url_params($url_self, ['action' => 'set_as_representative'])]);
            }
            if (Config::pictureEditIcon()) {
                $tpl->assign('U_PHOTO_ADMIN', ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $imageId));
            }
            if (Config::pictureCaddieIcon()) {
                $tpl->assign('U_CADDIE', add_url_params($url_self, ['action' => 'add_to_caddie']));
            }
        }

        if (!is_a_guest() && Config::pictureFavoriteIcon()) {
            $is_favorite = ServiceLocator::get(UserRepository::class)->isFavorite(
                is_numeric($user['id']) ? (int) $user['id'] : 0,
                $imageId
            );
            $tpl->assign('favorite', ['IS_FAVORITE' => $is_favorite, 'U_FAVORITE' => add_url_params($url_self, ['action' => !$is_favorite ? 'add_to_favorites' : 'remove_from_favorites'])]);
        }

        // Picture info
        $infos = [];
        if (isset($picture['current']['comment']) && !empty($picture['current']['comment'])) {
            $tpl->assign('COMMENT_IMG', trigger_change('render_element_description', $picture['current']['comment'], 'picture_page_element_description'));
        }
        if (!empty($currentPic['author'] ?? null)) {
            $infos['INFO_AUTHOR'] = $currentPic['author'];
        }
        if (!empty($currentPic['date_creation'])) {
            $dc   = (is_string($currentPic['date_creation']) || is_int($currentPic['date_creation'])) ? $currentPic['date_creation'] : null;
            $val  = format_date($dc);
            $url  = make_index_url(['chronology_field' => 'created', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($dc) ? (string) $dc : '', 0, 10))]);
            $infos['INFO_CREATION_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
        }
        $da  = (isset($currentPic['date_available']) && (is_string($currentPic['date_available']) || is_int($currentPic['date_available']))) ? $currentPic['date_available'] : null;
        $val = format_date($da);
        $url = make_index_url(['chronology_field' => 'posted', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($da) ? (string) $da : '', 0, 10))]);
        $infos['INFO_POSTED_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';

        if ($currentSrcImage !== null && $currentSrcImage->is_original() && isset($currentPic['width'])) {
            $infos['INFO_DIMENSIONS'] = (is_scalar($currentPic['width']) ? (string) $currentPic['width'] : '') . '*' . (is_scalar($currentPic['height'] ?? null) ? (string) $currentPic['height'] : '');
        }
        if (!empty($currentPic['filesize'] ?? null)) {
            $infos['INFO_FILESIZE'] = l10n('%d Kb', $currentPic['filesize']);
        }
        $infos['INFO_VISITS'] = $currentPic['hit'] ?? null;
        $infos['INFO_FILE']   = $currentPic['file'] ?? null;

        $tpl->assign($infos);
        $tpl->assign('display_info', unserialize(Config::pictureInformations() ?? ''));

        // Related tags
        $tags = get_common_tags([$imageId], -1);
        foreach ($tags as $tag) {
            $tagArr = is_array($tag) ? $tag : [];
            $tpl->append('related_tags', array_merge($tagArr, ['URL' => make_index_url(['tags' => [$tag]]), 'U_TAG_IMAGE' => duplicate_picture_url(['section' => 'tags', 'tags' => [$tag]])]));
        }

        // Related categories
        if (count($related_categories) == 1 && $category !== null && $related_categories[0]['id'] == $catId) {
            $upperNames = is_array($category['upper_names'] ?? null) ? $category['upper_names'] : [];
            $tpl->append('related_categories', get_cat_display_name($upperNames));
        } else {
            $ids = [];
            foreach ($related_categories as $category) {
                $ids = array_merge($ids, explode(',', is_scalar($category['uppercats']) ? (string) $category['uppercats'] : ''));
            }
            $ids    = array_unique($ids);
            $query  = 'SELECT id, name, permalink FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $ids) . ')';
            $catMap = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'id');
            foreach ($related_categories as $category) {
                $cats = [];
                foreach (explode(',', is_scalar($category['uppercats']) ? (string) $category['uppercats'] : '') as $id) {
                    $cats[] = $catMap[$id];
                }
                $tpl->append('related_categories', get_cat_display_name($cats));
            }
        }

        if (in_array(strtolower(get_extension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : '')), ['pdf'])) {
            $tpl->assign(['PDF_VIEWER_FILESIZE_THRESHOLD' => Config::pdfViewerFilesizeThreshold() * 1024, 'PDF_NB_PAGES' => count_pdf_pages(is_string($currentPic['path'] ?? null) ? $currentPic['path'] : '')]);
        }

        $element_content = trigger_change('render_element_content', '', $picture['current']);
        $tpl->assign('ELEMENT_CONTENT', $element_content);

        $nextPic      = is_array($picture['next'] ?? null) ? $picture['next'] : null;
        $nextSrcImage = ($nextPic !== null && ($nextPic['src_image'] ?? null) instanceof SrcImage) ? $nextPic['src_image'] : null;
        if ($nextSrcImage !== null && $nextSrcImage->is_original() && $tpl->get_template_vars('U_PREFETCH') == null
            && !str_contains(is_scalar($_SERVER['HTTP_USER_AGENT'] ?? null) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', 'Chrome/')
        ) {
            $derivType  = pwg_get_session_var('picture_deriv', Config::derivativeDefaultSize());
            $nextDerivs = is_array($nextPic['derivatives'] ?? null) ? $nextPic['derivatives'] : [];
            $nextDeriv  = ($nextDerivs[$derivType] ?? null) instanceof DerivativeImage ? $nextDerivs[$derivType] : null;
            if ($nextDeriv !== null) {
                $tpl->assign('U_PREFETCH', $nextDeriv->get_url());
            }
        }

        $tpl->assign('U_CANONICAL', make_picture_url(['image_id' => $currentPic['id'] ?? null, 'image_file' => $currentPic['file'] ?? null]));

        require PHPWG_ROOT_PATH . 'include/picture_rate.inc.php';
        if (Config::activateComments()) {
            require PHPWG_ROOT_PATH . 'include/picture_comment.inc.php';
        }
        if ($metadata_showable && isset($_SESSION['pwg_show_metadata'])) {
            require PHPWG_ROOT_PATH . 'include/picture_metadata.inc.php';
        }

        $themeconf    = $tpl->get_template_vars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (Config::pictureMenu() && !in_array('thePicturePage', $hideMenuOn)) {
            if (!isset($page['start'])) {
                $page['start'] = 0;
            }
            require PHPWG_ROOT_PATH . 'include/menubar.inc.php';
        }

        require PHPWG_ROOT_PATH . 'include/page_header.php';
        trigger_notify('loc_end_picture');
        flush_page_messages();
        if ($page['slideshow'] && Config::lightSlideshow()) {
            $tpl->pparse('slideshow');
        } else {
            $tpl->parse_picture_buttons();
            $tpl->pparse('picture');
        }

        $picIdRaw = $currentPic['id'] ?? null;
        pwg_log((is_int($picIdRaw) || is_string($picIdRaw)) ? $picIdRaw : null, 'picture');
        require PHPWG_ROOT_PATH . 'include/page_tail.php';

        return ResponseFactory::create(200);
    }
}
