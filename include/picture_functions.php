<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\TemplateRegistry;

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

/**
 * Default handler for the render_element_content event.
 * Generates the picture display HTML via the picture_content.tpl template.
 *
 * @param array<string,mixed> $element_info
 */
function default_picture_content(string $content, array $element_info): string
{
    if (!empty($content)) {
        return $content;
    }

    $cookie_picture_deriv = input_string('picture_deriv', null, $_COOKIE);
    if ($cookie_picture_deriv !== null) {
        if (array_key_exists($cookie_picture_deriv, ImageStdParams::get_defined_type_map())) {
            pwg_set_session_var('picture_deriv', $cookie_picture_deriv);
        }
        setcookie('picture_deriv', '', ['expires' => 0, 'path' => cookie_path() ?? '']);
    }

    $deriv_type         = pwg_get_session_var('picture_deriv', Config::derivativeDefaultSize());
    $derivativesRaw     = is_array($element_info['derivatives'] ?? null) ? $element_info['derivatives'] : [];
    $selected_derivative = $derivativesRaw[$deriv_type] ?? null;

    $unique_derivatives = [];
    $show_original      = isset($element_info['element_url']);
    $added              = [];

    foreach ($derivativesRaw as $type => $derivative) {
        if ($type == IMG_SQUARE || $type == IMG_THUMB) {
            continue;
        }
        if (!array_key_exists((string) $type, ImageStdParams::get_defined_type_map())) {
            continue;
        }
        if (!($derivative instanceof DerivativeImage)) {
            continue;
        }
        $url = $derivative->get_url();
        if (!is_string($url)) {
            continue;
        }
        if (isset($added[$url])) {
            continue;
        }
        $added[$url]    = 1;
        $show_original  &= !($derivative->same_as_source());

        if (Config::pictureSizesIcon() || $type == $deriv_type) {
            $unique_derivatives[$type] = $derivative;
        }
    }

    $tpl = TemplateRegistry::current();
    if ($show_original) {
        $tpl->assign('U_ORIGINAL', $element_info['element_url']);
    }
    $tpl->append('current', ['selected_derivative' => $selected_derivative, 'unique_derivatives' => $unique_derivatives], true);
    $tpl->set_filenames(['default_content' => 'picture_content.tpl']);
    $tpl->assign(['ALT_IMG' => $element_info['file'], 'COOKIE_PATH' => cookie_path()]);

    return (string) $tpl->parse('default_content', true);
}
