<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Logger;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Util;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativePipeline;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageDerivativeContext;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Plugins\EventDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles image derivative (thumbnail/resize) generation and serving.
 *
 * Helpers (`ierror`, `parseRequest`, `sendDerivative`, etc.) are static
 * methods on `Piwigo\Image\DerivativePipeline`.
 *
 * This controller sends binary output directly (fpassthru) and returns an
 * empty 200 response — the ResponseEmitter will see headers_sent() and do
 * nothing.
 */
final readonly class ImageDerivativeController implements ControllerInterface
{
    public function __construct(private Connection $conn)
    {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        RequestContextRegistry::set(RequestContext::Derivative);

        $logger = LoggerRegistry::current();

        $ctx   = new ImageDerivativeContext();
        $begin = $step = microtime(true);
        $timing = [];
        foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
            $timing[$k] = '';
        }

        $prefixeTable  = Config::dbPrefix();

        foreach ($this->conn->executeQuery(
            'SELECT param, value FROM ' . $prefixeTable . "config WHERE param IN ('derivatives', 'disabled_derivatives')"
        )->fetchAllAssociative() as $row) {
            if (is_string($row['param'] ?? null)) {
                Config::override($row['param'], $row['value']);
            }
        }
        ImageStdParams::loadFromDb();

        $dpRaw  = DerivativePipeline::parseRequest($ctx);
        $params = EventDispatcher::dispatch('derivative_params_get', $dpRaw);

        $src_mtime = Filesystem::tryFileMtime($ctx->srcPath);
        if ($src_mtime === false) {
            DerivativePipeline::ierror('Source not found', 404);
        }

        $need_generate   = false;
        $derivative_mtime = Filesystem::tryFileMtime($ctx->derivativePath);
        if ($derivative_mtime === false
            || $derivative_mtime < $src_mtime
            || $derivative_mtime < $params->last_mod_time
        ) {
            $need_generate   = true;
            $derivative_mtime = ($derivative_mtime !== false) ? $derivative_mtime : 0;
        }

        $expires = false;
        $now     = time();
        if (isset($_GET['b'])) {
            $expires = $now + 100;
            header('Cache-control: no-store, max-age=100');
        } elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {
            $expires = $now + 10 * 24 * 3600;
        }

        if (!$need_generate) {
            $ifModifiedSinceRaw = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
            if (is_string($ifModifiedSinceRaw)
                && strtotime($ifModifiedSinceRaw) == $derivative_mtime
            ) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT', true, 304);
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT', true, 304);
                exit;
            }
            DerivativePipeline::sendDerivative($expires, $ctx);
            exit;
        }

        $ctx->coi = null;
        if (!str_contains($ctx->srcLocation, '/pwg_representative/')
            && !str_contains($ctx->srcLocation, 'themes/')
            && !str_contains($ctx->srcLocation, 'plugins/')
        ) {
            try {
                $query = '
SELECT *
  FROM ' . $prefixeTable . 'images
  WHERE path=\'' . addslashes($ctx->srcLocation) . '\'
;';
                $rowResult = $this->conn->executeQuery($query)->fetchAssociative();
                $row = $rowResult !== false ? $rowResult : null;
                if ($row !== null) {
                    if (isset($row['width'])) {
                        $ctx->originalSize = [
                            is_numeric($row['width']) ? (int) $row['width'] : 0,
                            is_numeric($row['height']) ? (int) $row['height'] : 0,
                        ];
                    }
                    $ctx->coi = is_string($row['coi'] ?? null) ? $row['coi'] : null;
                    if (!isset($row['rotation'])) {
                        $ctx->rotationAngle = PwgImage::getRotationAngle($ctx->srcPath);
                        $this->conn->update(
                            $prefixeTable . 'images',
                            ['rotation' => PwgImage::getRotationCodeFromAngle($ctx->rotationAngle ?? 0)],
                            ['id' => $row['id']]
                        );
                    } else {
                        $ctx->rotationAngle = PwgImage::getRotationAngleFromCode(
                            is_numeric($row['rotation']) ? (int) $row['rotation'] : 0
                        );
                    }
                }
                if ($row === null) {
                    DerivativePipeline::ierror('Db file path not found', 404);
                }
            } catch (\Exception $e) {
                $logger->error($e->getMessage());
            }
        } else {
            $ctx->rotationAngle = 0;
        }
        $this->conn->close();

        if (!DerivativePipeline::trySwitchSource($params, $src_mtime, $ctx) && $params->type == DerivativeSize::Custom->value) {
            $sharpen = 0.0;
            foreach (ImageStdParams::getDefinedTypeMap() as $std_params) {
                $sharpen += $std_params->sharpen;
            }
            $params->sharpen = (int) round($sharpen / (float) count(ImageStdParams::getDefinedTypeMap()));
        }

        $derivativeDir = dirname($ctx->derivativePath);
        if (!is_dir($derivativeDir) && !Util::mkgetdir($derivativeDir, MKGETDIR_RECURSIVE)) {
            DerivativePipeline::ierror('dir create error', 500);
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $image = new PwgImage($ctx->srcPath);
        $timing['load'] = DerivativePipeline::timeStep($step);

        $changes = 0;

        if (0 != $ctx->rotationAngle) {
            $image->rotate((int) $ctx->rotationAngle);
            $changes++;
            $timing['rotate'] = DerivativePipeline::timeStep($step);
        }

        $o_size    = $d_size = [$image->getWidth(), $image->getHeight()];
        $crop_rect = null;
        $scaled_size = null;
        $params->sizing->compute($o_size, $ctx->coi, $crop_rect, $scaled_size);
        if ($crop_rect !== null) {
            $changes++;
            $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
            $timing['crop'] = DerivativePipeline::timeStep($step);
        }

        if ($scaled_size !== null) {
            $changes++;
            $image->resize((int) $scaled_size[0], (int) $scaled_size[1]);
            $d_size         = $scaled_size;
            $timing['scale'] = DerivativePipeline::timeStep($step);
        }

        if ($params->sharpen) {
            $changes += (int) $image->sharpen((int) $params->sharpen);
            $timing['sharpen'] = DerivativePipeline::timeStep($step);
        }

        if ($params->willWatermark($d_size)) {
            $wm       = ImageStdParams::getWatermark();
            $wm_image = new PwgImage(PHPWG_ROOT_PATH . $wm->file);
            $wm_size  = [$wm_image->getWidth(), $wm_image->getHeight()];
            if ($d_size[0] < $wm_size[0] || $d_size[1] < $wm_size[1]) {
                $wm_scaling_params = SizingParams::classic((int) $d_size[0], (int) $d_size[1]);
                $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
                if ($wm_scaled_size !== null) {
                    $wm_size = $wm_scaled_size;
                    $wm_image->resize((int) $wm_scaled_size[0], (int) $wm_scaled_size[1]);
                }
            }
            $x = round(((float) $wm->xpos / 100.0) * ((float) $d_size[0] - (float) $wm_size[0]));
            $y = round(((float) $wm->ypos / 100.0) * ((float) $d_size[1] - (float) $wm_size[1]));
            if ($image->compose($wm_image, (int) $x, (int) $y, $wm->opacity)) {
                $changes++;
                if ($wm->xrepeat || $wm->yrepeat) {
                    $xpad = (float) $wm_size[0] + max(30.0, round((float) $wm_size[0] / 4.0));
                    $ypad = (float) $wm_size[1] + max(30.0, round((float) $wm_size[1] / 4.0));
                    for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                        for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                            if (!$i && !$j) {
                                continue;
                            }
                            $x2 = $x + (float) $i * $xpad;
                            $y2 = $y + (float) $j * $ypad;
                            if ($x2 >= 0 && $x2 + (float) $wm_size[0] < (float) $d_size[0] &&
                                $y2 >= 0 && $y2 + (float) $wm_size[1] < (float) $d_size[1]) {
                                if (!$image->compose($wm_image, (int) $x2, (int) $y2, $wm->opacity)) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            $wm_image->destroy();
            $timing['watermark'] = DerivativePipeline::timeStep($step);
        }

        if (!$changes) {
            header('X-i: No change');
            DerivativePipeline::ierror($ctx->srcUrl, 301);
        }

        if ((float) $d_size[0] * (float) $d_size[1] < (float) Config::derivativesStripMetadataThreshold()) {
            $image->strip();
        }

        $compression_quality = ImageStdParams::$quality;
        if (in_array($ctx->derivativeType, [DerivativeSize::FourXLarge->value, DerivativeSize::ThreeXLarge->value])) {
            $compression_quality = min(ImageStdParams::$quality, 75);
        }

        $image->write($ctx->derivativePath);
        $image->destroy();
        Filesystem::tryChmod($ctx->derivativePath, Config::chmodValue() & 0o666);
        $timing['save'] = DerivativePipeline::timeStep($step);

        DerivativePipeline::sendDerivative($expires, $ctx);
        $timing['send'] = DerivativePipeline::timeStep($step);

        $timing['total'] = DerivativePipeline::timeStep($begin);

        if ($logger instanceof Logger && $logger->severity() >= Logger::DEBUG) {
            $logger->debug('image timing', [
                'src_path'        => basename($ctx->srcPath),
                'derivative_path' => basename($ctx->derivativePath),
                'o_size'          => (string) $o_size[0] . ' ' . (string) $o_size[1] . ' ' . (int) ((float) $o_size[0] * (float) $o_size[1]),
                'd_size'          => (string) $d_size[0] . ' ' . (string) $d_size[1] . ' ' . (int) ((float) $d_size[0] * (float) $d_size[1]),
                'mem_usage'       => function_exists('memory_get_peak_usage') ? round((float) memory_get_peak_usage() / (1024.0 * 1024.0), 1) : '',
                'timing'          => $timing,
                'quality'         => $compression_quality,
            ]);
        }

        return ResponseFactory::create(200);
    }
}
