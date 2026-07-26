<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

/**
 * Ported from admin/picture_coi.php (page slug "picture_coi").
 */
final class PictureCoiPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();
        $conn = DbConnection::build();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('image_id', $_GET, false, ValidationPattern::ID);

        // check_input_parameter() only validates the raw $_GET value against
        // ValidationPattern::ID (or dies); it does not narrow $_GET's type for PHPStan, so
        // re-derive a real int here for every later use.
        $image_id = 0;
        if (isset($_GET['image_id']) && is_numeric($_GET['image_id'])) {
            $image_id = (int) $_GET['image_id'];
        }

        if (isset($_POST['submit'])) {
            $coi_l = $_POST['l'] ?? null;
            $coi_l_str = is_string($coi_l) ? $coi_l : '';

            if ($coi_l_str === '') {
                $coi = null;
            } else {
                $to_fraction = (static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0);

                $coi = DerivativeUrlCodec::fractionToChar($to_fraction($coi_l))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['t'] ?? null))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['r'] ?? null))
                  . DerivativeUrlCodec::fractionToChar($to_fraction($_POST['b'] ?? null));
            }
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                ->updateCoi($image_id, $coi);
        }

        $image = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
            ->findById($image_id);
        if ($image === null) {
            $htmlRenderer->pageNotFound($this->redirectService, 'Requested photo does not exist');
        }
        $row = $image->toArray();

        if (isset($_POST['submit'])) {
            $derivative_infos = [
                'path' => $row['path'],
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
            \Piwigo\Config\CurrentConfig::setQuestionMarkInUrls(true);
            \Piwigo\Config\CurrentConfig::setPhpExtensionInUrls(true);
            if (\Piwigo\Config\CurrentConfig::derivativeUrlStyle() === 1) {
                \Piwigo\Config\CurrentConfig::setDerivativeUrlStyle(0); // auto
            }
        } else {
            $uid = '';
        }

        $tpl_var = [
            'TITLE' => $htmlRenderer->renderElementName($row),
            'ALT' => $row['file'],
            'U_IMG' => DerivativeImage::url(ImageStdParams::LARGE, $row),
        ];

        $coi_raw = $row['coi'] ?? null;
        $row_coi = is_string($coi_raw) ? $coi_raw : '';
        if ($row_coi !== '' && $row_coi !== '0') {
            $tpl_var['coi'] = [
                'l' => DerivativeUrlCodec::charToFraction($row_coi[0]),
                't' => DerivativeUrlCodec::charToFraction($row_coi[1]),
                'r' => DerivativeUrlCodec::charToFraction($row_coi[2]),
                'b' => DerivativeUrlCodec::charToFraction($row_coi[3]),
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
