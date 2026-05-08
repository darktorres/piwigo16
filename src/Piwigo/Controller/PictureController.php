<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Exception\NotFoundException;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureContentRenderer;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\PictureService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Section\SectionInitializer;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
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

        ServiceLocator::get(SectionInitializer::class)->initialize(
            $request->withAttribute('_route_path', '/' . ($args['rest'] ?? '')),
            'picture'
        );

        UserService::get()->saveEditContext();
        PermissionService::get()->checkStatus(AccessLevel::Guest);

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
            ServiceLocator::get(CategoryService::class)->checkRestrictions($catId);
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
                ServiceLocator::get(HtmlService::class)->pageNotFound('The requested image does not exist', UrlService::get()->duplicateIndexUrl());
                return ResponseFactory::create(404);
            }
            if (is_numeric($row['level'] ?? null) && is_numeric($user['level'] ?? null) && $row['level'] > $user['level']) {
                ServiceLocator::get(HtmlService::class)->accessDenied();
            }
            $page['image_id']   = $row['id'];
            $page['image_file'] = $row['file'];
            $imageId = is_scalar($row['id'] ?? null) ? (int) $row['id'] : 0;

            if (!isset($page['rank_of'][$imageId])) {
                $filter          = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
                $visibleImages   = is_scalar($filter['visible_images'] ?? null) ? (string) $filter['visible_images'] : '';
                if ($visibleImages !== '' && !in_array($imageId, explode(',', $visibleImages))) {
                    ServiceLocator::get(HtmlService::class)->pageNotFound('The requested image is filtered', UrlService::get()->duplicateIndexUrl());
                    return ResponseFactory::create(404);
                }
                if ('categories' == $page['section'] && !isset($page['category'])) {
                    ServiceLocator::get(HtmlService::class)->accessDenied();
                } else {
                    $query = '
SELECT id
  FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE id=' . $imageId
                        . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'category_id'], ' AND') . '
  LIMIT 1';
                    if (ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne() === false) {
                        ServiceLocator::get(HtmlService::class)->accessDenied();
                    } else {
                        if ('best_rated' == $page['section']) {
                            $page['rank_of'][$imageId] = count($items);
                            $items[]                   = $imageId;
                            $page['items']             = $items;
                        } else {
                            $url = UrlService::get()->makePictureUrl(['image_id' => $imageId, 'image_file' => is_scalar($page['image_file'] ?? null) ? $page['image_file'] : '', 'section' => 'categories', 'flat' => true]);
                            ServiceLocator::get(HtmlService::class)->setStatusHeader('recent_pics' == $page['section'] ? 301 : 302);
                            Util::get()->redirectHttp($url);
                        }
                    }
                }
            }
        }

        if (StringUtil::get()->inputString('metadata', null, $_GET) !== null) {
            if (ServiceLocator::get(SessionService::class)->getSessionVar('show_metadata') == null) {
                ServiceLocator::get(SessionService::class)->setSessionVar('show_metadata', 1);
            } else {
                ServiceLocator::get(SessionService::class)->unsetSessionVar('show_metadata');
            }
        }

        EventDispatcher::addListener('render_element_content', PictureContentRenderer::defaultContent(...));
        EventDispatcher::addListener('render_element_description', 'pwg_nl2br');

        EventDispatcher::notify('loc_begin_picture');

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

        $url_up = UrlService::get()->duplicateIndexUrl(
            ['start' => (int) floor($currentRank / $nbImagePage) * $nbImagePage],
            ['start']
        );
        $url_self = UrlService::get()->duplicatePictureUrl();

        // Actions
        $get_action = StringUtil::get()->inputString('action', null, $_GET);
        if ($get_action !== null) {
            switch ($get_action) {
                case 'add_to_favorites':
                    ServiceLocator::get(UserRepository::class)->addFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    Util::get()->redirect($url_self);
                    break;
                case 'remove_from_favorites':
                    ServiceLocator::get(UserRepository::class)->deleteFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    Util::get()->redirect('favorites' == $page['section'] ? $url_up : $url_self);
                    break;
                case 'set_as_representative':
                    if (PermissionService::get()->isAdmin() && $category !== null) {
                        ServiceLocator::get(CategoryRepository::class)->setRepresentativePicture([$catId], $imageId);
                        ServiceLocator::get(Util::class)->pwgActivity('album', $catId, 'edit', ['action' => $get_action, 'image_id' => $imageId]);
                        ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
                    }
                    Util::get()->redirect($url_self);
                    break;
                case 'add_to_caddie':
                    ServiceLocator::get(Util::class)->fillCaddie([$imageId]);
                    Util::get()->redirect($url_self);
                    break;
                case 'rate':
                    ServiceLocator::get(RateService::class)->ratePicture($imageId, StringUtil::get()->inputInt('rate', 0, $_POST));
                    Util::get()->redirect($url_self);
                    break;
                case 'edit_comment':
                    ServiceLocator::get(Util::class)->checkInputParameter('comment_to_edit', $_GET, false, ValidationPattern::ID);
                    $comment_to_edit = StringUtil::get()->inputInt('comment_to_edit', null, $_GET);
                    $author_id       = ServiceLocator::get(CommentService::class)->getCommentAuthorId($comment_to_edit ?? 0);
                    if (PermissionService::get()->canManageComment('edit', (int) $author_id)) {
                        $post_content = StringUtil::get()->inputString('content', null, $_POST);
                        if (!empty($post_content)) {
                            ServiceLocator::get(Util::class)->checkPwgToken();
                            $comment_action = ServiceLocator::get(CommentService::class)->updateUserComment(
                                ['comment_id' => $comment_to_edit, 'image_id' => $imageId, 'content' => $post_content, 'website_url' => StringUtil::get()->inputString('website_url', null, $_POST)],
                                StringUtil::get()->inputString('key', null, $_POST) ?? ''
                            );
                            $perform_redirect = false;
                            switch ($comment_action) {
                                case 'moderate':
                                    PageState::current()->addInfo(Lang::t('An administrator must authorize your comment before it is visible.'));
                                    // no break
                                case 'validate':
                                    PageState::current()->addInfo(Lang::t('Your comment has been registered'));
                                    $perform_redirect = true;
                                    break;
                                case 'reject':
                                    PageState::current()->addError(Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
                                    break;
                                default:
                                    trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                            }
                            if ($perform_redirect) {
                                Util::get()->redirect($url_self);
                            }
                            unset($_POST['content']);
                        }
                        $edit_comment = $comment_to_edit;
                    }
                    break;
                case 'delete_comment':
                    ServiceLocator::get(Util::class)->checkPwgToken();
                    ServiceLocator::get(Util::class)->checkInputParameter('comment_to_delete', $_GET, false, ValidationPattern::ID);
                    $author_id = ServiceLocator::get(CommentService::class)->getCommentAuthorId(StringUtil::get()->inputInt('comment_to_delete', null, $_GET) ?? 0);
                    if (PermissionService::get()->canManageComment('delete', (int) $author_id)) {
                        ServiceLocator::get(CommentService::class)->deleteUserComment(StringUtil::get()->inputInt('comment_to_delete', null, $_GET) ?? 0);
                    }
                    Util::get()->redirect($url_self);
                    break;
                case 'validate_comment':
                    ServiceLocator::get(Util::class)->checkPwgToken();
                    ServiceLocator::get(Util::class)->checkInputParameter('comment_to_validate', $_GET, false, ValidationPattern::ID);
                    $author_id = ServiceLocator::get(CommentService::class)->getCommentAuthorId(StringUtil::get()->inputInt('comment_to_validate', null, $_GET) ?? 0);
                    if (PermissionService::get()->canManageComment('validate', (int) $author_id)) {
                        ServiceLocator::get(CommentService::class)->validateUserComment(StringUtil::get()->inputInt('comment_to_validate', null, $_GET) ?? 0);
                    }
                    Util::get()->redirect($url_self);
                    break;
            }
        }

        // Hit counter
        $inc_hit_count = StringUtil::get()->inputString('content', null, $_POST) === null;
        if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch') {
            $inc_hit_count = false;
        } else {
            if (ServiceLocator::get(SessionService::class)->getSessionVar('referer_image_id', 0) == $imageId) {
                $inc_hit_count = false;
            }
            ServiceLocator::get(SessionService::class)->setSessionVar('referer_image_id', $imageId);
        }
        if (EventDispatcher::dispatch('allow_increment_element_hit_count', $inc_hit_count, $imageId)) {
            ServiceLocator::get(PictureService::class)->increaseImageVisitCounter($imageId);
        }

        // Related categories
        $query = '
SELECT id,uppercats,commentable,visible,status,global_rank
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'AND') . '
;';
        $related_categories = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        usort($related_categories, ServiceLocator::get(CategoryService::class)->globalRankCompare(...));

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
            $row['derivatives'] = DerivativeImage::getAll($row['src_image']);
            $row['path_ext']   = strtolower(ServiceLocator::get(StringUtil::class)->getExtension($src_path));
            $row['file_ext']   = strtolower(ServiceLocator::get(StringUtil::class)->getExtension($src_file));

            if ($i == 'current') {
                $row['element_path'] = ServiceLocator::get(StringUtil::class)->getElementPath($row);
                if ($row['src_image']->isOriginal()) {
                    if ($user['enabled_high'] == 'true') {
                        $row['element_url']  = $row['src_image']->getUrl();
                        $row['download_url'] = UrlService::get()->getActionUrl($src_id, 'e', true);
                    }
                } else {
                    $row['element_url']  = UrlService::get()->getElementUrl($row);
                    $row['download_url'] = UrlService::get()->getActionUrl($src_id, 'e', true);
                }
            }

            $row['url'] = UrlService::get()->duplicatePictureUrl(['image_id' => $row['id'], 'image_file' => $row['file']], ['start']);
            $row['TITLE']     = ServiceLocator::get(HtmlService::class)->renderElementName($row);
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
        $get_slideshow        = StringUtil::get()->inputString('slideshow', null, $_GET);

        if ($get_slideshow !== null) {
            $page['slideshow']    = true;
            $page['meta_robots']  = ['noindex' => 1, 'nofollow' => 1];
            $slideshow_params     = ServiceLocator::get(PictureService::class)->decodeSlideshowParams($get_slideshow);
            $slideshow_url_params['slideshow'] = ServiceLocator::get(PictureService::class)->encodeSlideshowParams($slideshow_params);

            if ($slideshow_params['play']) {
                $id_pict_redirect = '';
                if (isset($page['next_item'])) {
                    $id_pict_redirect = 'next';
                } elseif ($slideshow_params['repeat'] && isset($page['first_item'])) {
                    $id_pict_redirect = 'first';
                }
                if (!empty($id_pict_redirect) && isset($picture[$id_pict_redirect])) {
                    $refresh  = $slideshow_params['period'];
                    $url_link = UrlService::get()->addUrlParams((string) $picture[$id_pict_redirect]['url'], $slideshow_url_params);
                }
            }
        } else {
            $page['slideshow'] = false;
        }

        if ($page['slideshow'] && Config::lightSlideshow()) {
            $tpl->setFilenames(['slideshow' => 'slideshow.tpl']);
        } else {
            $tpl->setFilenames(['picture' => 'picture.tpl']);
        }

        $title    = (string) $picture['current']['TITLE'];
        $title_nb = ($currentRank + 1) . '/' . count($items);

        $url_metadata     = UrlService::get()->duplicatePictureUrl();
        $url_metadata     = UrlService::get()->addUrlParams($url_metadata, ['metadata' => null]);
        $curSrcImg = $picture['current']['src_image'];
        $metadata_showable = EventDispatcher::dispatch(
            'get_element_metadata_available',
            (Config::showExif() || Config::showIptc()) && !$curSrcImg->isMimetype(),
            $picture['current']
        );

        if (StringUtil::get()->inputString('metadata', null, $_GET) !== null) {
            $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];
        }

        $page['body_id'] = 'thePicturePage';

        /** @var array<string, array<string, mixed>> $picture */
        $picture    = EventDispatcher::dispatch('picture_pictures_data', $picture);
        $currentPic = is_array($picture['current'] ?? null) ? $picture['current'] : [];
        $currentSrcImage = ($currentPic['src_image'] ?? null) instanceof SrcImage ? $currentPic['src_image'] : null;

        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $imgArr = $picture[$which_image];
                $tpl->assign($which_image, array_merge($imgArr, ['U_IMG' => UrlService::get()->addUrlParams(is_string($imgArr['url'] ?? null) ? $imgArr['url'] : '', $slideshow_url_params)]));
            }
        }

        if (Config::pictureDownloadIcon() && !empty($picture['current']['download_url']) && $user['enabled_high'] == 'true') {
            $tpl->append('current', ['U_DOWNLOAD' => $picture['current']['download_url']], true);

            if (Config::isFormatsEnabled()) {
                $query = '
SELECT *
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . (is_scalar($currentPic['id'] ?? null) ? (int) $currentPic['id'] : 0) . '
;';
                $formats = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
                array_unshift($formats, [
                    'download_url' => is_scalar($currentPic['download_url'] ?? null) ? $currentPic['download_url'] : '',
                    'ext'          => ServiceLocator::get(StringUtil::class)->getExtension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : ''),
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
                $tpl_slideshow[$var_name] = UrlService::get()->addUrlParams($currentUrl, ['slideshow' => ServiceLocator::get(PictureService::class)->encodeSlideshowParams(array_merge($slideshow_params, [$p => !$slideshow_params[$p]]))]);
            }
            foreach (['dec', 'inc'] as $op) {
                $periodRaw = $slideshow_params['period'] ?? 0;
                $new_period  = (is_numeric($periodRaw) ? $periodRaw : 0) + (($op == 'dec' ? -1 : 1) * Config::slideshowPeriodStep());
                $new_params  = ServiceLocator::get(PictureService::class)->correctSlideshowParams(array_merge($slideshow_params, ['period' => $new_period]));
                if ($new_params['period'] === $new_period) {
                    $tpl_slideshow['U_' . strtoupper($op) . '_PERIOD'] = UrlService::get()->addUrlParams($currentUrl, ['slideshow' => ServiceLocator::get(PictureService::class)->encodeSlideshowParams($new_params)]);
                }
            }
            $tpl->assign('slideshow', $tpl_slideshow);
        } elseif (Config::pictureSlideShowIcon()) {
            $currentUrl = is_string($picture['current']['url'] ?? null) ? $picture['current']['url'] : '';
            $tpl->assign(['U_SLIDESHOW_START' => UrlService::get()->addUrlParams($currentUrl, ['slideshow' => ''])]);
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

        if (PermissionService::get()->isAdmin()) {
            if (isset($page['category']) && Config::pictureRepresentativeIcon()) {
                $tpl->assign(['U_SET_AS_REPRESENTATIVE' => UrlService::get()->addUrlParams($url_self, ['action' => 'set_as_representative'])]);
            }
            if (Config::pictureEditIcon()) {
                $tpl->assign('U_PHOTO_ADMIN', ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $imageId));
            }
            if (Config::pictureCaddieIcon()) {
                $tpl->assign('U_CADDIE', UrlService::get()->addUrlParams($url_self, ['action' => 'add_to_caddie']));
            }
        }

        if (!PermissionService::get()->isAGuest() && Config::pictureFavoriteIcon()) {
            $is_favorite = ServiceLocator::get(UserRepository::class)->isFavorite(
                is_numeric($user['id']) ? (int) $user['id'] : 0,
                $imageId
            );
            $tpl->assign('favorite', ['IS_FAVORITE' => $is_favorite, 'U_FAVORITE' => UrlService::get()->addUrlParams($url_self, ['action' => !$is_favorite ? 'add_to_favorites' : 'remove_from_favorites'])]);
        }

        // Picture info
        $infos = [];
        if (isset($picture['current']['comment']) && !empty($picture['current']['comment'])) {
            $tpl->assign('COMMENT_IMG', EventDispatcher::dispatch('render_element_description', $picture['current']['comment'], 'picture_page_element_description'));
        }
        if (!empty($currentPic['author'] ?? null)) {
            $infos['INFO_AUTHOR'] = $currentPic['author'];
        }
        if (!empty($currentPic['date_creation'])) {
            $dc   = (is_string($currentPic['date_creation']) || is_int($currentPic['date_creation'])) ? $currentPic['date_creation'] : null;
            $val  = ServiceLocator::get(DateService::class)->formatDate($dc);
            $url  = UrlService::get()->makeIndexUrl(['chronology_field' => 'created', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($dc) ? (string) $dc : '', 0, 10))]);
            $infos['INFO_CREATION_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
        }
        $da  = (isset($currentPic['date_available']) && (is_string($currentPic['date_available']) || is_int($currentPic['date_available']))) ? $currentPic['date_available'] : null;
        $val = ServiceLocator::get(DateService::class)->formatDate($da);
        $url = UrlService::get()->makeIndexUrl(['chronology_field' => 'posted', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($da) ? (string) $da : '', 0, 10))]);
        $infos['INFO_POSTED_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';

        if ($currentSrcImage !== null && $currentSrcImage->isOriginal() && isset($currentPic['width'])) {
            $infos['INFO_DIMENSIONS'] = (is_scalar($currentPic['width']) ? (string) $currentPic['width'] : '') . '*' . (is_scalar($currentPic['height'] ?? null) ? (string) $currentPic['height'] : '');
        }
        if (!empty($currentPic['filesize'] ?? null)) {
            $filesize = $currentPic['filesize'];
            $infos['INFO_FILESIZE'] = Lang::t('%d Kb', is_numeric($filesize) ? (int) $filesize : 0);
        }
        $infos['INFO_VISITS'] = $currentPic['hit'] ?? null;
        $infos['INFO_FILE']   = $currentPic['file'] ?? null;

        $tpl->assign($infos);
        $tpl->assign('display_info', unserialize(Config::pictureInformations() ?? ''));

        // Related tags
        $tags = ServiceLocator::get(TagService::class)->getCommonTags([$imageId], -1);
        foreach ($tags as $tag) {
            $tagArr = is_array($tag) ? $tag : [];
            $tpl->append('related_tags', array_merge($tagArr, ['URL' => UrlService::get()->makeIndexUrl(['tags' => [$tag]]), 'U_TAG_IMAGE' => UrlService::get()->duplicatePictureUrl(['section' => 'tags', 'tags' => [$tag]])]));
        }

        // Related categories
        if (count($related_categories) == 1 && $category !== null && $related_categories[0]['id'] == $catId) {
            $upperNames = is_array($category['upper_names'] ?? null) ? $category['upper_names'] : [];
            $tpl->append('related_categories', ServiceLocator::get(HtmlService::class)->getCatDisplayName($upperNames));
        } else {
            $ids = [];
            foreach ($related_categories as $category) {
                $ids = array_merge($ids, explode(',', is_scalar($category['uppercats']) ? (string) $category['uppercats'] : ''));
            }
            $ids    = array_unique($ids);
            $query  = 'SELECT id, name, permalink FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $ids) . ')';
            $catMap = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'id');
            foreach ($related_categories as $category) {
                $cats = [];
                foreach (explode(',', is_scalar($category['uppercats']) ? (string) $category['uppercats'] : '') as $id) {
                    $cats[] = $catMap[$id];
                }
                $tpl->append('related_categories', ServiceLocator::get(HtmlService::class)->getCatDisplayName($cats));
            }
        }

        if (in_array(strtolower(ServiceLocator::get(StringUtil::class)->getExtension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : '')), ['pdf'])) {
            $tpl->assign(['PDF_VIEWER_FILESIZE_THRESHOLD' => Config::pdfViewerFilesizeThreshold() * 1024, 'PDF_NB_PAGES' => ServiceLocator::get(PictureService::class)->countPdfPages(is_string($currentPic['path'] ?? null) ? $currentPic['path'] : '')]);
        }

        $element_content = EventDispatcher::dispatch('render_element_content', '', $picture['current']);
        $tpl->assign('ELEMENT_CONTENT', $element_content);

        $nextPic      = is_array($picture['next'] ?? null) ? $picture['next'] : null;
        $nextSrcImage = ($nextPic !== null && ($nextPic['src_image'] ?? null) instanceof SrcImage) ? $nextPic['src_image'] : null;
        if ($nextSrcImage !== null && $nextSrcImage->isOriginal() && $tpl->getTemplateVars('U_PREFETCH') == null
            && !str_contains(is_scalar($_SERVER['HTTP_USER_AGENT'] ?? null) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', 'Chrome/')
        ) {
            $derivRaw   = ServiceLocator::get(SessionService::class)->getSessionVar('picture_deriv', Config::derivativeDefaultSize());
            $derivType  = is_string($derivRaw) ? $derivRaw : Config::derivativeDefaultSize();
            $nextDerivs = is_array($nextPic['derivatives'] ?? null) ? $nextPic['derivatives'] : [];
            $nextDeriv  = ($nextDerivs[$derivType] ?? null) instanceof DerivativeImage ? $nextDerivs[$derivType] : null;
            if ($nextDeriv !== null) {
                $tpl->assign('U_PREFETCH', $nextDeriv->getUrl());
            }
        }

        $tpl->assign('U_CANONICAL', UrlService::get()->makePictureUrl(['image_id' => $currentPic['id'] ?? null, 'image_file' => $currentPic['file'] ?? null]));

        ServiceLocator::get(PictureRateRenderer::class)->render();
        if (Config::activateComments()) {
            ServiceLocator::get(PictureCommentRenderer::class)->render($edit_comment ?? null);
        }
        if ($metadata_showable && isset($_SESSION['pwg_show_metadata'])) {
            ServiceLocator::get(PictureMetadataRenderer::class)->render();
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (Config::pictureMenu() && !in_array('thePicturePage', $hideMenuOn)) {
            if (!isset($page['start'])) {
                $page['start'] = 0;
            }
            ServiceLocator::get(MenubarRenderer::class)->render();
        }

        PageHeaderRenderer::render($title, isset($refresh) && is_int($refresh) ? $refresh : null, $url_link ?? null);
        EventDispatcher::notify('loc_end_picture');
        ServiceLocator::get(HtmlService::class)->flushPageMessages();
        if ($page['slideshow'] && Config::lightSlideshow()) {
            $tpl->pparse('slideshow');
        } else {
            $tpl->parsePictureButtons();
            $tpl->pparse('picture');
        }

        $picIdRaw = $currentPic['id'] ?? null;
        ServiceLocator::get(Util::class)->pwgLog((is_int($picIdRaw) || is_string($picIdRaw)) ? $picIdRaw : null, 'picture');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
