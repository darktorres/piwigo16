<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Paths;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class DerivativeService
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private Paths $paths,
    ) {
    }
    /**
     * Generate (or skip if already current) one derivative file for a given image row and size type.
     *
     * @param array<string, mixed> $imageRow  Full row from the images table (path, rotation, coi, …)
     * @param string               $type      One of the IMG_* constants (e.g. DerivativeSize::Thumb->value, DerivativeSize::Medium->value)
     */
    public function generate(array $imageRow, string $type): void
    {
        $srcImage = new SrcImage($imageRow);
        if ($srcImage->rel_path === '') {
            return;
        }

        ImageStdParams::loadFromDb();

        $defined = ImageStdParams::getDefinedTypeMap();
        if (!isset($defined[$type])) {
            return;
        }

        $params     = $defined[$type];
        $derivImage = new DerivativeImage($params, $srcImage);
        if ($derivImage->sameAsSource()) {
            return;
        }

        $srcPath   = $this->paths->root . $srcImage->rel_path;
        $derivPath = $derivImage->getPath();

        $srcMtime = Filesystem::tryFileMtime($srcPath);
        if ($srcMtime === false) {
            return;
        }

        $derivMtime = Filesystem::tryFileMtime($derivPath);
        if ($derivMtime !== false
            && $derivMtime >= $srcMtime
            && $derivMtime >= $params->last_mod_time) {
            return;
        }

        $derivDir = dirname($derivPath);
        if (!is_dir($derivDir)) {
            Filesystem::mkgetdir($derivDir, Filesystem::FLAG_RECURSIVE | Filesystem::FLAG_PROTECT_INDEX);
        }

        $rotationCode  = is_numeric($imageRow['rotation'] ?? null) ? (int) $imageRow['rotation'] : 0;
        $rotationAngle = PwgImage::getRotationAngleFromCode($rotationCode);
        $coi           = is_string($imageRow['coi'] ?? null) ? $imageRow['coi'] : null;

        $image = new PwgImage($srcPath, $this->dispatcher);

        if ($rotationAngle !== 0) {
            $image->rotate($rotationAngle);
        }

        /** @var int[] $o_size */
        $o_size = [$image->getWidth(), $image->getHeight()];
        /** @var array<int, int|float> $d_size */
        $d_size     = $o_size;
        $crop_rect  = null;
        $scale_size = null;
        $params->sizing->compute($o_size, $coi, $crop_rect, $scale_size);

        if ($crop_rect !== null) {
            $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
        }

        if ($scale_size !== null) {
            $image->resize((int) $scale_size[0], (int) $scale_size[1]);
            $d_size = $scale_size;
        }

        if ($params->sharpen) {
            $image->sharpen((int) $params->sharpen);
        }

        if ($params->willWatermark($d_size)) {
            $wm       = ImageStdParams::getWatermark();
            $wm_image = new PwgImage($this->paths->root . $wm->file, $this->dispatcher);
            /** @var array<int, int> $wm_size */
            $wm_size  = [$wm_image->getWidth(), $wm_image->getHeight()];

            if ($d_size[0] < $wm_size[0] || $d_size[1] < $wm_size[1]) {
                $wm_scaling = SizingParams::classic((int) $d_size[0], (int) $d_size[1]);
                $wm_tmp     = null;
                $wm_scaled  = null;
                $wm_scaling->compute($wm_size, null, $wm_tmp, $wm_scaled);
                if ($wm_scaled !== null) {
                    $wm_size = [(int) $wm_scaled[0], (int) $wm_scaled[1]];
                    $wm_image->resize((int) $wm_scaled[0], (int) $wm_scaled[1]);
                }
            }

            $x = (int) round(((float) $wm->xpos / 100.0) * ((float) $d_size[0] - (float) $wm_size[0]));
            $y = (int) round(((float) $wm->ypos / 100.0) * ((float) $d_size[1] - (float) $wm_size[1]));
            $image->compose($wm_image, $x, $y, $wm->opacity);
            $wm_image->destroy();
        }

        if ((int) $d_size[0] * (int) $d_size[1] < Config::derivativesStripMetadataThreshold()) {
            $image->strip();
        }

        $image->write($derivPath);
        $image->destroy();
        Filesystem::tryChmod($derivPath, Config::chmodValue() & 0o666);
    }

    /** @return string[] All currently-enabled derivative type names */
    public function getDefinedTypes(): array
    {
        ImageStdParams::loadFromDb();
        return array_keys(ImageStdParams::getDefinedTypeMap());
    }
}
