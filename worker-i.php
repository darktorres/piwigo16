<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Piwigo\admin\inc\pwg_image;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SizingParams;
use Piwigo\inc\WorkerExitException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

const PHPWG_ROOT_PATH = './';

// --- Bootstrap once ---

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/inc/config_default.php';

if (file_exists(__DIR__ . '/local/config/config.php')) {
    require __DIR__ . '/local/config/config.php';
}

if (! defined('PWG_LOCAL_DIR')) {
    define('PWG_LOCAL_DIR', 'local/');
}

if (! defined('PWG_DERIVATIVE_DIR')) {
    define('PWG_DERIVATIVE_DIR', $conf->data_location . 'i/');
}

if (file_exists(__DIR__ . '/local/config/database.php')) {
    require __DIR__ . '/local/config/database.php';
}

$logFile = './' . $conf->data_location . $conf->log_dir . '/log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $conf->db_password) . '.txt';
$logger = new Logger('piwigo');
$logger->pushHandler(new StreamHandler($logFile, $conf->log_level));

$conf->sql_backend::pwg_db_connect(
    $conf->db_host,
    $conf->db_user,
    $conf->db_password,
    $conf->db_base
);

$query = <<<SQL
    SELECT value FROM config WHERE param = 'derivatives';
    SQL;
[$tmp] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

if ($conf->dblayer === 'pgsql') {
    $tmp = stripslashes((string) $tmp);
}

$conf->derivatives = unserialize($tmp);
ImageStdParams::load_from_db();

functions::$workerMode = true;

// --- Worker loop ---

$worker  = Worker::create();
$factory = new Psr17Factory();
$psr7    = new PSR7Worker($worker, $factory, $factory, $factory);

while ($request = $psr7->waitRequest()) {
    try {
        $response = handleDerivativeRequest($request, $conf, $logger);
    } catch (\Throwable $e) {
        $logger->error($e->getMessage());
        $response = new Response(500, [], 'Internal Server Error');
    }

    $psr7->respond($response);
}

// --- Request handler ---

function handleDerivativeRequest(
    \Psr\Http\Message\ServerRequestInterface $request,
    $conf,
    $logger
): \Psr\Http\Message\ResponseInterface {
    global $page;

    $page = [];

    // Populate superglobals that parse_request() and send_derivative() rely on
    $_SERVER['PATH_INFO']    = $request->getUri()->getPath();
    $_SERVER['QUERY_STRING'] = $request->getUri()->getQuery();
    $_SERVER['REQUEST_URI']  = (string) $request->getUri();
    $_GET = $request->getQueryParams();

    $begin = microtime(true);
    $step  = $begin;

    try {
        functions::parse_request();

        $params     = $page['derivative_params'];
        $src_mtime  = file_exists($page['src_path']) ? filemtime($page['src_path']) : false;

        if ($src_mtime === false) {
            functions::ierror('Source not found', 404);
        }

        $need_generate    = false;
        $derivative_mtime = file_exists($page['derivative_path']) ? filemtime($page['derivative_path']) : false;

        if ($derivative_mtime === false || $derivative_mtime < $src_mtime || $derivative_mtime < $params->last_mod_time) {
            $need_generate = true;
        }

        $expires = false;
        $now     = time();

        if (isset($_GET['b'])) {
            $expires = $now + 100;
        } elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {
            $expires = $now + 10 * 24 * 3600;
        }

        if (! $need_generate) {
            $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
            if ($ifModifiedSince && strtotime($ifModifiedSince) == $derivative_mtime) {
                return new Response(304, [
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT',
                    'Expires'       => gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT',
                    'X-Served-By'   => 'roadrunner',
                ]);
            }

            $data = functions::get_derivative_response($expires);
            return fileResponse($data);
        }

        // Need to generate — open DB per request (connection kept alive via persistent connect)
        $page['coi'] = null;

        if (! str_contains($page['src_location'], '/pwg_representative/') &&
            ! str_contains($page['src_location'], 'themes/') &&
            ! str_contains($page['src_location'], 'plugins/') &&
            ! str_starts_with($page['src_location'], './' . PWG_DERIVATIVE_DIR)
        ) {
            $escaped_path = $conf->sql_backend::pwg_db_real_escape_string($page['src_location']);
            $query = "SELECT * FROM images WHERE path = '{$escaped_path}'";
            $row   = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

            if ($row) {
                if (isset($row['width'])) {
                    $page['original_size'] = [$row['width'], $row['height']];
                }

                $page['coi'] = $row['coi'];

                if (! isset($row['rotation'])) {
                    $page['rotation_angle'] = pwg_image::get_rotation_angle($page['src_path']);
                    $conf->sql_backend::single_update(
                        'images',
                        ['rotation' => pwg_image::get_rotation_code_from_angle($page['rotation_angle'])],
                        ['id' => $row['id']]
                    );
                } else {
                    $page['rotation_angle'] = pwg_image::get_rotation_angle_from_code($row['rotation']);
                }
            }

            if (! $row) {
                functions::ierror('Db file path not found', 404);
            }
        } else {
            $page['rotation_angle'] = 0;
        }

        if (! functions::try_switch_source($params, $src_mtime) &&
            $params->type == derivative_std_params::IMG_CUSTOM
        ) {
            $sharpen = 0;

            foreach (ImageStdParams::get_defined_type_map() as $std_params) {
                $sharpen += $std_params->sharpen;
            }

            $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
        }

        if (! functions::mkgetdir(dirname($page['derivative_path']))) {
            functions::ierror('dir create error', 500);
        }

        $image   = new pwg_image($page['src_path']);
        $changes = 0;

        if ($page['rotation_angle'] != 0) {
            $image->rotate($page['rotation_angle']);
            $changes++;
        }

        $o_size = [$image->get_width(), $image->get_height()];
        $d_size = [$image->get_width(), $image->get_height()];
        $params->sizing->compute($o_size, $page['coi'], $crop_rect, $scaled_size);

        if ($crop_rect) {
            $changes++;
            $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
        }

        if ($scaled_size) {
            $changes++;
            $image->resize($scaled_size[0], $scaled_size[1]);
            $d_size = $scaled_size;
        }

        if ($params->sharpen) {
            $changes += $image->sharpen($params->sharpen);
        }

        if ($params->will_watermark($d_size)) {
            $wm       = ImageStdParams::get_watermark();
            $wm_image = new pwg_image('./' . $wm->file);
            $wm_size  = [$wm_image->get_width(), $wm_image->get_height()];

            if ($d_size[0] < $wm_size[0] || $d_size[1] < $wm_size[1]) {
                $wm_scaling_params = SizingParams::classic($d_size[0], $d_size[1]);
                $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
                $wm_size = $wm_scaled_size;
                $wm_image->resize($wm_scaled_size[0], $wm_scaled_size[1]);
            }

            $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
            $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));

            if ($image->compose($wm_image, $x, $y, $wm->opacity)) {
                $changes++;

                if ($wm->xrepeat || $wm->yrepeat) {
                    $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
                    $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));

                    for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                        for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                            if (! $i && ! $j) {
                                continue;
                            }

                            $x2 = $x + $i * $xpad;
                            $y2 = $y + $j * $ypad;

                            if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] &&
                                $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1] &&
                                ! $image->compose($wm_image, $x2, $y2, $wm->opacity)
                            ) {
                                break;
                            }
                        }
                    }
                }
            }

            $wm_image->destroy();
        }

        if (! $changes) {
            // No transformation needed — redirect to source
            return new Response(301, ['Location' => html_entity_decode($page['src_url'])]);
        }

        if ($conf->derivatives_strip_metadata_threshold > $d_size[0] * $d_size[1]) {
            $image->strip();
        }

        $image->set_compression_quality(ImageStdParams::$quality);
        $image->write($page['derivative_path']);
        $image->destroy();
        chmod($page['derivative_path'], 0644);

        $data = functions::get_derivative_response($expires);
        return fileResponse($data);

    } catch (WorkerExitException $e) {
        $code = $e->getCode();

        if ($code === 301 || $code === 302) {
            return new Response($code, ['Location' => html_entity_decode($e->getMessage())]);
        }

        return new Response($code ?: 500, [], $e->getMessage());
    }
}

/**
 * @param array{status: int, headers: array<string, string>, path: string} $data
 */
function fileResponse(array $data): \Psr\Http\Message\ResponseInterface
{
    $factory = new Psr17Factory();
    $body    = $factory->createStreamFromFile($data['path'], 'rb');
    return new Response($data['status'], $data['headers'], $body);
}
