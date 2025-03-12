<?php
declare(strict_types=1);

namespace Piwigo\themes\modus;

use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\DerivativeParams;
use Piwigo\inc\functions;
use Piwigo\inc\functions_cookie;
use Piwigo\inc\functions_session;
use Piwigo\inc\ImageRect;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\Script;
use Piwigo\inc\Template;
use Smarty_Internal_Template;

final class functions_modus
{
    public static function modus_css_gradient(
        array $gradient
    ): ?string {
        if (! empty($gradient)) {
            $std = implode(',', $gradient);
            $gs = trim($gradient[0], '#');
            $ge = trim($gradient[1], '#');
            return "filter: progid:DXImageTransform.Microsoft.gradient(startColorStr=#FF{$gs},endColorStr=#FF{$ge}); /* IE to 9*/
                background-image: -moz-linear-gradient(top,{$std}); /* FF 3.16 to 15 */
                background-image: -webkit-linear-gradient(top,{$std}); /* Chrome, Safari */
                background-image: -ms-linear-gradient(top,{$std}); /* IE ? to 9 */
                background-image: -o-linear-gradient(top,{$std}); /* Opera 11 to 12 */
                background-image: linear-gradient(to bottom,{$std}); /* Standard must be last */";
        }

        return null;
    }

    public static function modus_get_default_config(): array
    {
        return [
            'skin' => 'newspaper',
            'album_thumb_size' => 250,
            'index_photo_deriv' => '2small',
            'index_photo_deriv_hdpi' => 'xsmall',
            'display_page_banner' => false,
        ];
    }

    public static function modus_smarty_prefilter(
        array|string $source
    ): array|string|null {
        global $lang, $conf;

        $source = str_replace('<div id="imageHeaderBar">', '<div class=titrePage id=imageHeaderBar>', $source);
        $source = str_replace('<div id=imageHeaderBar>', '<div class=titrePage id=imageHeaderBar>', $source);

        if (! isset($lang['modus_theme'])) {
            functions::load_language('theme.lang', dirname(__FILE__) . '/');
        }

        // picture page actionButtons wrap for mobile
        if (strpos($source, '<div id="imageToolBar">') !== false ||
            strpos($source, '<div id=imageToolBar>') !== false
        ) {
            $pos = strpos($source, '<div class="actionButtons">');

            if (! $pos) {
                $pos = strpos($source, '<div class=actionButtons>');
            }

            if ($pos !== false) {
                $source = substr_replace($source, '<div class=actionButtonsWrapper><a id=imageActionsSwitch class=pwg-button><span class="pwg-icon pwg-icon-ellipsis"></span></a>{combine_script version=1 id=\'modus.async\' path="themes/`$themeconf.id`/js/modus.async.js" load=\'async\'}', $pos, 0);
                $pos = strpos($source, 'caddie', $pos + 1);
                $pos = strpos($source, '</div>', $pos + 1);
                $source = substr_replace($source, '</div>', $pos, 0);
            }
        }

        /* move imageNumber from imageToolBar to imageHeaderBar*/
        if (preg_match('#<div[ a-zA-Z"=]+id="?imageHeaderBar"?>#', $source, $matches, PREG_OFFSET_CAPTURE) &&
            preg_match('#<div class="?imageNumber"?>{\\$PHOTO}</div>#', $source, $matches2, PREG_OFFSET_CAPTURE, $matches[0][1] + 20)
        ) {
            $source = substr_replace($source, '', $matches2[0][1], strlen($matches2[0][0]));
            $source = substr_replace($source, $matches2[0][0], $matches[0][1] + strlen($matches[0][0]), 0);
        }

        $pos = strpos($source, '<ul class="categoryActions">') ?? strpos($source, '<ul class=categoryActions>');

        if ($pos !== false) {
            $pos2 = strpos($source, '</ul>', $pos);

            if ($pos2 !== false &&
                substr_count($source, '<li>', $pos, $pos2 - $pos) > 2
            ) {
                $source = substr_replace($source, '<a id=albumActionsSwitcher class=pwg-button><span class="pwg-icon pwg-icon-ellipsis"></span></a>{combine_script version=1 id=\'modus.async\' path="themes/`$themeconf.id`/js/modus.async.js" load=\'async\'}', $pos, 0);
            }
        }

        $re = preg_quote('<img title="{$cat.icon_ts.TITLE}" src="', '/')
                . '[^>]+'
                . preg_quote('/recent{if $cat.icon_ts.IS_CHILD_DATE}_by_child{/if}.png"', '/')
                . '[^>]+'
                . preg_quote('alt="(!)">', '/');
        $source = preg_replace(
            '/' . $re . '/',
            '<span class=albSymbol title="{$cat.icon_ts.TITLE}">{if $cat.icon_ts.IS_CHILD_DATE}' . MODUS_STR_RECENT_CHILD . '{else}' . MODUS_STR_RECENT . '{/if}</span>',
            $source
        );

        $re = preg_quote('<img title="{$thumbnail.icon_ts.TITLE}" src="', '/')
            . '[^>]+'
            . preg_quote('/recent.png" alt="(!)">', '/');
        $source = preg_replace(
            '/' . $re . '/',
            '<span class=albSymbol title="{$thumbnail.icon_ts.TITLE}">' . MODUS_STR_RECENT . '</span>',
            $source
        );

        return $source;
    }

    public static function modus_smarty_prefilter_wrap(
        array|string $source
    ): array|string|null {
        return self::modus_smarty_prefilter($source);
    }

    public static function rv_cdn_prefilter(
        string $source,
        Smarty_Internal_Template &$smarty
    ): array|string {
        $source = str_replace('src="{$ROOT_URL}{$themeconf.icon_dir}/', 'src="' . RVCDN_ROOT_URL . '{$themeconf.icon_dir}/', $source);
        $source = str_replace('url({$ROOT_URL}', 'url(' . RVCDN_ROOT_URL, $source);
        return $source;
    }

    public static function modus_loc_begin_index(): void
    {
        global $template;
        $template->set_prefilter('index', self::modus_index_prefilter_1(...));
        $template->set_prefilter('index', self::modus_index_prefilter_2(...));
    }

    public static function modus_index_prefilter_1(
        array|string $content
    ): array|string {
        $search = '{combine_css path="themes/default/fontello/css/fontello.css" order=-10}';
        $replacement = '';
        return str_replace($search, $replacement, $content);
    }

    public static function modus_index_prefilter_2(
        array|string $content
    ): array|string {
        $search = '<span class="pwg-icon-search-folder"></span>';
        $replacement = '<span class="pwg-icon pwg-icon-search-folder"></span>';
        return str_replace($search, $replacement, $content);
    }

    public static function rv_cdn_combined_script(
        string $url,
        Script $script
    ): string {
        if (! $script->is_remote()) {
            $url = RVCDN_ROOT_URL . $script->path;
        }

        return $url;
    }

    public static function modus_loc_begin_page_header(): void
    {
        $all = $GLOBALS['template']->scriptLoader->get_all();
        $jq = $all['jquery'];

        if ($jq) {
            $jq->set_path(RVPT_JQUERY_SRC);
        }
    }

    public static function modus_combinable_preparse(
        Template $template
    ): void {
        global $conf, $template;

        if (! isset($template->smarty->registered_plugins['modifier']['cssGradient'])) {
            $template->smarty->registerPlugin('modifier', 'cssGradient', self::modus_css_gradient(...));
        }

        require dirname(__FILE__) . '/skins/' . $conf['modus_theme']['skin'] . '.php';

        $template->assign([
            'conf' => $conf,
            'skin' => $skin,
            'MODUS_ALBUM_THUMB_SIZE' => intval($conf['modus_theme']['album_thumb_size']),
            'SQUARE_WIDTH' => ImageStdParams::get_by_type(derivative_std_params::IMG_SQUARE)->max_width(),
            'loaded_plugins' => $GLOBALS['pwg_loaded_plugins'],
        ]);
    }

    public static function modus_css_resolution(
        array $params
    ): string {
        $base = ($params['base'] ?? null);
        $min = $params['min'];
        $max = ($params['max'] ?? null);

        $rules = [];

        if (! empty($base)) {
            $rules[] = $base;
        }

        foreach (['min', 'max'] as $type) {
            if (! empty(${$type})) {
                $rules[] = '(-webkit-' . $type . '-device-pixel-ratio:' . ${$type} . ')';
            }
        }

        $res = implode(' and ', $rules);

        $rules = [];

        if (! empty($base)) {
            $rules[] = $base;
        }

        foreach (['min', 'max'] as $type) {
            if (! empty(${$type})) {
                $rules[] = '(' . $type . '-resolution:' . round(96 * ${$type}, 1) . 'dpi)';
            }
        }

        $res .= ',' . implode(' and ', $rules);

        return $res;
    }

    public static function modus_thumbs(
        array $x,
        Smarty_Internal_Template $smarty
    ): void {
        global $template, $page, $conf;

        $default_params = $smarty->getTemplateVars('derivative_params');
        $row_height = $default_params->max_height();
        $device = functions::get_device();
        $container_margin = 5;

        if ($device == 'mobile') {
            $horizontal_margin = floor(0.01 * $row_height);
            $container_margin = 0;
        } elseif ($device == 'tablet') {
            $horizontal_margin = floor(0.015 * $row_height);
        } else {
            $horizontal_margin = floor(0.02 * $row_height);
        }

        $vertical_margin = $horizontal_margin + 1;

        $candidates = [$default_params];

        foreach (ImageStdParams::get_defined_type_map() as $params) {
            if ($params->max_height() > $row_height &&
                $params->sizing->max_crop == $default_params->sizing->max_crop
            ) {
                $candidates[] = $params;

                if (count($candidates) == 3) {
                    break;
                }
            }
        }

        $do_over = $device == 'desktop';

        $new_icon = ' <span class=albSymbol title="' . functions::l10n('posted on %s') . '">' . MODUS_STR_RECENT . '</span>';

        foreach ($smarty->getTemplateVars('thumbnails') as $item) {
            $src_image = $item['src_image'];
            $new = ! empty($item['icon_ts']) ? sprintf($new_icon, functions::format_date($item['date_available'])) : '';

            $idx = 0;

            do {
                $cparams = $candidates[$idx];
                $c = new DerivativeImage($cparams, $src_image);
                $csize = $c->get_size();
                $idx++;
            } while ($csize[1] < $row_height - 2 && $idx < count($candidates));

            $a_style = '';

            if ($csize[1] < $row_height) {
                $a_style = ' style="top:' . floor(($row_height - $csize[1]) / 2) . 'px"';
            } elseif ($csize[1] > $row_height) {
                $csize = $c->get_scaled_size(9999, $row_height);
            }

            // Create class names and styles
            $li_class = 'path-ext-' . $item['path_ext'] . ' file-ext-' . $item['file_ext'];
            $li_style = 'width:' . $csize[0] . 'px; height:' . $row_height . 'px;';
            $img_src = $c->get_url();
            $img_width = $csize[0];
            $img_height = $csize[1];
            $img_alt = $item['TN_ALT'];
            $item_name = $item['NAME'];
            $item_url = $item['URL'];

            // Render the HTML block
            ?>
            <li class="<?= $li_class ?>" style="<?= $li_style ?>">
                <a href="<?= $item_url ?>"<?= $a_style ?>>
                    <img src="<?= $img_src ?>" width="<?= $img_width ?>" height="<?= $img_height ?>" alt="<?= $img_alt ?>">
                </a>
            <?php if ($do_over): ?>
                <div class="overDesc"><?= $item_name . $new ?></div>
            <?php endif; ?>
            </li>
            <?php
        }

        $template->block_html_style(
            null,
            '
                <style>
                    #thumbnails {
                        text-align: justify;
                        overflow: hidden;
                        margin-left: ' . ($container_margin - $horizontal_margin) . 'px;
                        margin-right: ' . $container_margin . 'px;
                    }

                    #thumbnails > li {
                        float: left;
                        overflow: hidden;
                        position: relative;
                        margin-bottom: ' . $vertical_margin . 'px;
                        margin-left: ' . $horizontal_margin . 'px;
                    }

                    #thumbnails > li > a {
                        position: absolute;
                        border: 0;
                    }
                </style>'
        );
        $template->block_footer_script(
            null,
            '
                <script>
                    rvgtProcessor = new RVGThumbs({
                        hMargin: ' . $horizontal_margin . ',
                        rowHeight: ' . $row_height . '
                    });
                </script>'
        );

        $my_base_name = basename(dirname(__FILE__));
        // not async to avoid visible flickering reflow
        $template->scriptLoader->add('modus.arange', 1, ['jquery'], 'themes/' . $my_base_name . '/js/thumb.arrange.js', 0);
    }

    public static function modus_on_end_index(): void
    {
        global $template;

        if (! functions_session::pwg_get_session_var('caps')) {
            $template->block_footer_script(
                null,
                '
                    <script>
                        try {
                            document.cookie = "caps=" +
                                (window.devicePixelRatio ? window.devicePixelRatio : 1) + "x" +
                                document.documentElement.clientWidth + "x" +
                                document.documentElement.clientHeight +
                                ";path=' . functions_cookie::cookie_path() . '";
                        } catch (er) {
                            document.cookie = "caps=1x1x1x" + er.message;
                        }
                    </script>'
            );
        }

    }

    public static function modus_get_index_photo_derivative_params(
        DerivativeParams $default
    ): DerivativeParams {
        global $conf;

        if (isset($conf['modus_theme']) &&
            functions_session::pwg_get_session_var('index_deriv') === null
        ) {
            $type = $conf['modus_theme']['index_photo_deriv'];
            $caps = functions_session::pwg_get_session_var('caps');

            if ($caps) {
                if (($caps[0] >= 2 && $caps[1] >= 768) ||
                     $caps[0] >= 3
                ) { /*Ipad3 always has clientWidth 768 independently of orientation*/
                    $type = $conf['modus_theme']['index_photo_deriv_hdpi'];
                }
            }

            $new = ImageStdParams::get_by_type($type);

            if ($new) {
                return $new;
            }
        }

        return $default;
    }

    public static function modus_index_category_thumbnails(
        array $items
    ): array {
        global $page, $template, $conf;
        $wh = $conf['modus_theme']['album_thumb_size'];

        if ($page['section'] != 'categories' ||
            ! $wh
        ) {
            return $items;
        }

        $template->assign('album_thumb_size', $wh);

        $def_params = ImageStdParams::get_custom($wh, $wh, 1, $wh, $wh);

        foreach (ImageStdParams::get_defined_type_map() as $params) {
            if ($params->max_height() == $wh) {
                $alt_params = $params;
            }
        }

        foreach ($items as &$item) {
            $src_image = $item['representative']['src_image'];
            $src_size = $src_image->get_size();

            $item['path_ext'] = strtolower(functions::get_extension($item['representative']['path']));
            $item['file_ext'] = strtolower(functions::get_extension($item['representative']['file']));

            $deriv = null;

            if (isset($alt_params) &&
                $src_size[0] >= $src_size[1]
            ) {
                $dsize = $alt_params->compute_final_size($src_size);

                if ($dsize[0] >= $wh &&
                    $dsize[1] >= $wh
                ) {
                    $deriv = new DerivativeImage($alt_params, $src_image);
                    $rect = new ImageRect($dsize);
                    $rect->crop_h($dsize[0] - $wh, $item['representative']['coi']);
                    $rect->crop_v($dsize[1] - $wh, $item['representative']['coi']);
                    $l = -$rect->l;
                    $t = -$rect->t;
                }
            }

            if (! isset($deriv)) {
                $deriv = new DerivativeImage($def_params, $src_image);
                $dsize = $deriv->get_size();
                $l = intval($wh - $dsize[0]) / 2;
                $t = intval($wh - $dsize[1]) / 2;
            }

            $item['modus_deriv'] = $deriv;

            if (! empty($item['icon_ts'])) {
                $item['icon_ts']['TITLE'] = functions::time_since($item['max_date_last'], 'month');
            }

            $styles = [];

            if ($l < -1 ||
                $l > 1
            ) {
                $styles[] = 'left:' . (100 * $l / $wh) . '%';
            }

            if ($t < -1 ||
                $t > 1
            ) {
                $styles[] = 'top:' . $t . 'px';
            }

            if (count($styles)) {
                $styles = ' style=' . implode(';', $styles);
            } else {
                $styles = '';
            }

            $item['MODUS_STYLE'] = $styles;
        }

        return $items;
    }

    public static function modus_loc_begin_picture(): void
    {
        global $conf, $template;

        if (isset($_GET['slideshow'])) {
            $conf['picture_menu'] = false;
            return;
        }

        if (isset($_GET['map'])) {
            return;
        }

        $template->append(
            '
                <script>
                    if (document.documentElement.offsetWidth > 1270) {
                        document.documentElement.className = \'wide\';
                    }
                </script>'
        );
    }

    public static function modus_picture_content(
        string $content,
        array $element_info
    ): string|null {
        global $conf, $picture, $template;

        if (! empty($content)) { // someone hooked us - so we skip;
            return $content;
        }

        $unique_derivatives = [];
        $show_original = isset($element_info['element_url']);
        $added = [];

        foreach ($element_info['derivatives'] as $type => $derivative) {
            if ($type == derivative_std_params::IMG_SQUARE ||
                $type == derivative_std_params::IMG_THUMB
            ) {
                continue;
            }

            if (! array_key_exists($type, ImageStdParams::get_defined_type_map())) {
                continue;
            }

            $url = $derivative->get_url();

            if (isset($added[$url])) {
                continue;
            }

            $added[$url] = 1;
            $show_original &= ! ($derivative->same_as_source());
            $unique_derivatives[$type] = $derivative;
        }

        if (isset($_COOKIE['picture_deriv'])) { // ignore persistence
            setcookie('picture_deriv', '', 0, functions_cookie::cookie_path());
        }

        $selected_derivative = null;

        if (isset($_COOKIE['phavsz'])) {
            $available_size = explode('x', $_COOKIE['phavsz']);
        } else {
            $caps = functions_session::pwg_get_session_var('caps');

            if ($caps &&
                $caps[0] > 1
            ) {
                $available_size = [$caps[0] * $caps[1], $caps[0] * ($caps[2] - 100), $caps[0]];
            }
        }

        if (isset($available_size)) {
            foreach ($unique_derivatives as $derivative) {
                $size = $derivative->get_size();

                if (! $size) {
                    break;
                }

                if ($size[0] <= $available_size[0] and
                    $size[1] <= $available_size[1]
                ) {
                    $selected_derivative = $derivative;
                } else {
                    if ($available_size[2] > 1 ||
                        ! $selected_derivative
                    ) {
                        $selected_derivative = $derivative;
                    }
                    break;
                }
            }

            if ($available_size[2] > 1 &&
                $selected_derivative
            ) {
                $ratio_w = $size[0] / $available_size[0];
                $ratio_h = $size[1] / $available_size[1];

                if ($ratio_w > 1 ||
                    $ratio_h > 1
                ) {
                    if ($ratio_w > $ratio_h) {
                        $display_size = [$available_size[0] / $available_size[2], floor($size[1] / $ratio_w / $available_size[2])];
                    } else {
                        $display_size = [floor($size[0] / $ratio_h / $available_size[2]), $available_size[1] / $available_size[2]];
                    }
                } else {
                    $display_size = [round($size[0] / $available_size[2]), round($size[1] / $available_size[2])];
                }

                $template->assign([
                    'rvas_display_size' => $display_size,
                    'rvas_natural_size' => $size,
                ]);
            }

            if (isset($picture['next']) and
                $picture['next']['src_image']->is_original()
            ) {
                $next_best = null;

                foreach ($picture['next']['derivatives'] as $derivative) {
                    $size = $derivative->get_size();

                    if (! $size) {
                        break;
                    }

                    if ($size[0] <= $available_size[0] and
                        $size[1] <= $available_size[1]
                    ) {
                        $next_best = $derivative;
                    } else {
                        if ($available_size[2] > 1 ||
                            ! $next_best
                        ) {
                            $next_best = $derivative;
                        }
                        break;
                    }
                }

                if (isset($next_best)) {
                    $template->assign('U_PREFETCH', $next_best->get_url());
                }
            }
        }

        $as_pending = false;

        if (! $selected_derivative) {
            $as_pending = true;
            $selected_derivative = $element_info['derivatives'][functions_session::pwg_get_session_var('picture_deriv', $conf['derivative_default_size'])];
        }

        if ($show_original) {
            $template->assign('U_ORIGINAL', $element_info['element_url']);
        }

        $template->append('current', [
            'selected_derivative' => $selected_derivative,
            'unique_derivatives' => $unique_derivatives,
        ], true);

        $template->set_filenames(
            [
                'default_content' => 'picture_content_asize.tpl',
            ]
        );

        $template->assign(
            [
                'ALT_IMG' => $element_info['file'],
                'COOKIE_PATH' => functions_cookie::cookie_path(),
                'RVAS_PENDING' => $as_pending,
            ]
        );

        return $template->parse('default_content', true);
    }
}

?>
