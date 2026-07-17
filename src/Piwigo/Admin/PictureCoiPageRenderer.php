<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

/**
 * Ported from admin/picture_coi.php (page slug "picture_coi").
 */
final class PictureCoiPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = new HtmlService();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        (new \Piwigo\Validation\InputValidator())->validate('image_id', $_GET, false, ValidationPattern::ID);

        // check_input_parameter() only validates the raw $_GET value against
        // ValidationPattern::ID (or dies); it does not narrow $_GET's type for PHPStan, so
        // re-derive a real int here for every later use.
        $image_id = 0;
        if (isset($_GET['image_id']) && is_numeric($_GET['image_id'])) {
            $image_id = (int) $_GET['image_id'];
        }

        if (isset($_POST['submit'])) {
            $coi_l = $_POST['l'] ?? null;
            $coi_l_str = is_scalar($coi_l) ? (string) $coi_l : '';

            if ($coi_l_str === '') {
                $coi = null;
            } else {
                $to_fraction = (static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0);

                $coi = DerivativeUrlCodec::fractionToChar($to_fraction($coi_l))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['t'] ?? null))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['r'] ?? null))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['b'] ?? null));
            }
            new ImageRepository(DbConnection::build())->updateCoi($image_id, $coi);
        }

        $query = 'SELECT * FROM ' . Tables::images() . ' WHERE id=' . $image_id;
        $row = \Piwigo\Db\MysqliDb::fetchAssoc(\Piwigo\Db\MysqliDb::query($query));
        if (! is_array($row)) {
            $htmlRenderer->pageNotFound('Requested photo does not exist');
        }

        if (isset($_POST['submit'])) {
            $derivative_infos = [
                'path' => (string) $row['path'],
            ];
            if (isset($row['representative_ext']) && $row['representative_ext'] !== '' && $row['representative_ext'] !== '0') {
                $derivative_infos['representative_ext'] = $row['representative_ext'];
            }

            foreach (ImageStdParams::get_defined_type_map() as $params) {
                if ($params->sizing->max_crop !== 0.0) {
                    new DerivativeCacheService()
                        ->deleteElementDerivatives($derivative_infos, $params->type);
                }
            }
            new DerivativeCacheService()
                ->deleteElementDerivatives($derivative_infos, ImageStdParams::CUSTOM);
            $uid = '&b=' . time();
            $conf['question_mark_in_urls'] = $conf['php_extension_in_urls'] = true;
            if ($conf['derivative_url_style'] === 1) {
                $conf['derivative_url_style'] = 0; // auto
            }
        } else {
            $uid = '';
        }

        $tpl_var = [
            'TITLE' => $htmlRenderer->renderElementName($row),
            'ALT' => $row['file'],
            'U_IMG' => DerivativeImage::url(ImageStdParams::LARGE, $row),
        ];

        if (isset($row['coi']) && $row['coi'] !== '' && $row['coi'] !== '0') {
            $tpl_var['coi'] = [
                'l' => DerivativeUrlCodec::charToFraction($row['coi'][0]),
                't' => DerivativeUrlCodec::charToFraction($row['coi'][1]),
                'r' => DerivativeUrlCodec::charToFraction($row['coi'][2]),
                'b' => DerivativeUrlCodec::charToFraction($row['coi'][3]),
            ];
        }

        foreach (ImageStdParams::get_defined_type_map() as $params) {
            if ($params->sizing->max_crop !== 0.0) {
                $derivative = new DerivativeImage($params, new SrcImage($row));
                $template->append('cropped_derivatives', [
                    'U_IMG' => $derivative->get_url() . $uid,
                    'HTM_SIZE' => $derivative->get_size_htm(),
                ]);
            }
        }

        $template->assign($tpl_var);
        $template->set_filename('picture_coi', 'picture_coi.tpl');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_coi');
    }
}
