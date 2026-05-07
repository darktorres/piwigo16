<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\TemplateRegistry;

final class PictureContentRenderer
{
    /**
     * Default handler for the render_element_content event.
     *
     * @param array<string,mixed> $elementInfo
     */
    public static function defaultContent(string $content, array $elementInfo): string
    {
        if (!empty($content)) {
            return $content;
        }

        $cookiePictureDeriv = input_string('picture_deriv', null, $_COOKIE);
        if ($cookiePictureDeriv !== null) {
            if (array_key_exists($cookiePictureDeriv, ImageStdParams::getDefinedTypeMap())) {
                pwg_set_session_var('picture_deriv', $cookiePictureDeriv);
            }
            setcookie('picture_deriv', '', ['expires' => 0, 'path' => CookieService::cookiePath() ?? '']);
        }

        $derivType           = pwg_get_session_var('picture_deriv', Config::derivativeDefaultSize());
        $derivativesRaw      = is_array($elementInfo['derivatives'] ?? null) ? $elementInfo['derivatives'] : [];
        $selectedDerivative  = $derivativesRaw[$derivType] ?? null;

        $uniqueDerivatives = [];
        $showOriginal      = isset($elementInfo['element_url']);
        $added             = [];

        foreach ($derivativesRaw as $type => $derivative) {
            if ($type == IMG_SQUARE || $type == IMG_THUMB) {
                continue;
            }
            if (!array_key_exists((string) $type, ImageStdParams::getDefinedTypeMap())) {
                continue;
            }
            if (!($derivative instanceof DerivativeImage)) {
                continue;
            }
            $url = $derivative->getUrl();
            if (!is_string($url)) {
                continue;
            }
            if (isset($added[$url])) {
                continue;
            }
            $added[$url]   = 1;
            $showOriginal &= !($derivative->sameAsSource());

            if (Config::pictureSizesIcon() || $type == $derivType) {
                $uniqueDerivatives[$type] = $derivative;
            }
        }

        $tpl = TemplateRegistry::current();
        if ($showOriginal) {
            $tpl->assign('U_ORIGINAL', $elementInfo['element_url']);
        }
        $tpl->append('current', ['selected_derivative' => $selectedDerivative, 'unique_derivatives' => $uniqueDerivatives], true);
        $tpl->setFilenames(['default_content' => 'picture_content.tpl']);
        $tpl->assign(['ALT_IMG' => $elementInfo['file'], 'COOKIE_PATH' => CookieService::cookiePath()]);

        return (string) $tpl->parse('default_content', true);
    }
}
