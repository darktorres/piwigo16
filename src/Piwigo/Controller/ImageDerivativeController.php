<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Logger;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Db\DbConnection;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageDerivativeContext;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles image derivative (thumbnail/resize) generation and serving.
 * Corresponds to the former i.php entry-point.
 *
 * Helper functions (ierror, parse_request, send_derivative, etc.) live in
 * include/image_derivative_functions.php.
 *
 * This controller sends binary output directly (fpassthru) and returns an empty
 * 200 response — the ResponseEmitter will see headers_sent() and do nothing.
 */
final class ImageDerivativeController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        require_once PHPWG_ROOT_PATH . 'include/image_derivative_functions.php';
        require_once PHPWG_ROOT_PATH . 'include/derivative_params.inc.php';
        require_once PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php';

        $logger = LoggerRegistry::current();

        $ctx   = new ImageDerivativeContext();
        $begin = $step = microtime(true);
        $timing = [];
        foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
            $timing[$k] = '';
        }

        $conn          = DbConnection::build();
        $prefixeTable  = Config::dbPrefix();

        foreach ($conn->executeQuery(
            'SELECT param, value FROM ' . $prefixeTable . "config WHERE param IN ('derivatives', 'disabled_derivatives')"
        )->fetchAllAssociative() as $row) {
            if (is_string($row['param'] ?? null)) {
                Config::override($row['param'], $row['value']);
            }
        }
        ImageStdParams::load_from_db();

        $dpRaw  = parse_request($ctx);
        $params = trigger_change('derivative_params_get', $dpRaw);

        $src_mtime = Filesystem::tryFileMtime($ctx->srcPath);
        if ($src_mtime === false) {
            ierror('Source not found', 404);
        }

        $need_generate   = false;
        $derivative_mtime = Filesystem::tryFileMtime($ctx->derivativePath);
        if ($derivative_mtime === false
            || $derivative_mtime < $src_mtime
            || $derivative_mtime < $params->last_mod_time
        ) {
            $need_generate   = true;
            $derivative_mtime = $derivative_mtime ?: 0;
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
            if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                && is_scalar($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) == $derivative_mtime
            ) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT', true, 304);
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT', true, 304);
                exit;
            }
            send_derivative($expires, $ctx);
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
                $row = $conn->executeQuery($query)->fetchAssociative() ?: null;
                if ($row !== null) {
                    if (isset($row['width'])) {
                        $ctx->originalSize = [
                            is_numeric($row['width'])  ? (int) $row['width']  : 0,
                            is_numeric($row['height']) ? (int) $row['height'] : 0,
                        ];
                    }
                    $ctx->coi = is_string($row['coi'] ?? null) ? $row['coi'] : null;
                    if (!isset($row['rotation'])) {
                        $ctx->rotationAngle = PwgImage::get_rotation_angle($ctx->srcPath);
                        $conn->update(
                            $prefixeTable . 'images',
                            ['rotation' => PwgImage::get_rotation_code_from_angle($ctx->rotationAngle ?? 0)],
                            ['id' => $row['id']]
                        );
                    } else {
                        $ctx->rotationAngle = PwgImage::get_rotation_angle_from_code(
                            is_numeric($row['rotation']) ? (int) $row['rotation'] : 0
                        );
                    }
                }
                if (!$row) {
                    ierror('Db file path not found', 404);
                }
            } catch (\Exception $e) {
                $logger->error($e->getMessage());
            }
        } else {
            $ctx->rotationAngle = 0;
        }
        $conn->close();

        if (!try_switch_source($params, $src_mtime, $ctx) && $params->type == IMG_CUSTOM) {
            $sharpen = 0;
            foreach (ImageStdParams::get_defined_type_map() as $std_params) {
                $sharpen += $std_params->sharpen;
            }
            $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
        }

        if (!mkgetdir(dirname($ctx->derivativePath))) {
            ierror('dir create error', 500);
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $image = new PwgImage($ctx->srcPath);
        $timing['load'] = time_step($step);

        $changes = 0;

        if (0 != $ctx->rotationAngle) {
            $image->rotate((int) $ctx->rotationAngle);
            $changes++;
            $timing['rotate'] = time_step($step);
        }

        $o_size    = $d_size = [$image->get_width(), $image->get_height()];
        $crop_rect = null;
        $scaled_size = null;
        $params->sizing->compute($o_size, $ctx->coi, $crop_rect, $scaled_size);
        if ($crop_rect) {
            $changes++;
            $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
            $timing['crop'] = time_step($step);
        }

        if ($scaled_size) {
            $changes++;
            $image->resize((int) $scaled_size[0], (int) $scaled_size[1]);
            $d_size         = $scaled_size;
            $timing['scale'] = time_step($step);
        }

        if ($params->sharpen) {
            $changes += $image->sharpen((int) $params->sharpen);
            $timing['sharpen'] = time_step($step);
        }

        if ($params->will_watermark($d_size)) {
            $wm       = ImageStdParams::get_watermark();
            $wm_image = new PwgImage(PHPWG_ROOT_PATH . $wm->file);
            $wm_size  = [$wm_image->get_width(), $wm_image->get_height()];
            if ($d_size[0] < $wm_size[0] || $d_size[1] < $wm_size[1]) {
                $wm_scaling_params = SizingParams::classic((int) $d_size[0], (int) $d_size[1]);
                $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
                if ($wm_scaled_size !== null) {
                    $wm_size = $wm_scaled_size;
                    $wm_image->resize((int) $wm_scaled_size[0], (int) $wm_scaled_size[1]);
                }
            }
            $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
            $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
            if ($image->compose($wm_image, (int) $x, (int) $y, $wm->opacity)) {
                $changes++;
                if ($wm->xrepeat || $wm->yrepeat) {
                    $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
                    $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));
                    for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                        for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                            if (!$i && !$j) {
                                continue;
                            }
                            $x2 = $x + $i * $xpad;
                            $y2 = $y + $j * $ypad;
                            if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] &&
                                $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1]) {
                                if (!$image->compose($wm_image, (int) $x2, (int) $y2, $wm->opacity)) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            $wm_image->destroy();
            $timing['watermark'] = time_step($step);
        }

        if (!$changes) {
            header('X-i: No change');
            ierror($ctx->srcUrl, 301);
        }

        if ($d_size[0] * $d_size[1] < Config::derivativesStripMetadataThreshold()) {
            $image->strip();
        }

        $compression_quality = ImageStdParams::$quality;
        if (in_array($ctx->derivativeType, [IMG_4XLARGE, IMG_3XLARGE])) {
            $compression_quality = min(ImageStdParams::$quality, 75);
        }

        $image->write($ctx->derivativePath);
        $image->destroy();
        Filesystem::tryChmod($ctx->derivativePath, 0644);
        $timing['save'] = time_step($step);

        send_derivative($expires, $ctx);
        $timing['send'] = time_step($step);

        $timing['total'] = time_step($begin);

        if ($logger instanceof Logger && $logger->severity() >= Logger::DEBUG) {
            $logger->debug('image timing', [
                'src_path'        => basename($ctx->srcPath),
                'derivative_path' => basename($ctx->derivativePath),
                'o_size'          => $o_size[0] . ' ' . $o_size[1] . ' ' . ($o_size[0] * $o_size[1]),
                'd_size'          => $d_size[0] . ' ' . $d_size[1] . ' ' . ($d_size[0] * $d_size[1]),
                'mem_usage'       => function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage() / (1024 * 1024), 1) : '',
                'timing'          => $timing,
                'quality'         => $compression_quality,
            ]);
        }

        return ResponseFactory::create(200);
    }
}
