<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

$sse_mode = isset($_GET['sse']);

function gt_emit(string $event, array $data): void
{
    if (! $GLOBALS['sse_mode']) {
        return;
    }

    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function gt_curl_handle(string $url): \CurlHandle
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    return $ch;
}

if ($sse_mode && isset($_POST['submit'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    ignore_user_abort(false);
    set_time_limit(0);
    session_write_close();

    $query = 'SELECT id, path, representative_ext, width, height, rotation, coi FROM images ORDER BY id';
    $images = $conf->sql_backend::query2array($query);
    $total = count($images);

    if ($total === 0) {
        gt_emit('complete', [
            'generated' => 0,
            'skipped' => 0,
            'elapsed' => 0,
        ]);
        exit();
    }

    $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

    gt_emit('start', [
        'total' => $total,
    ]);

    $generated = 0;
    $skipped = 0;
    $phase_start = microtime(true);
    $defined_types = ImageStdParams::get_defined_type_map();

    foreach ($images as $i => $image) {
        $src_image = new SrcImage($image);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($defined_types as $params) {
            $derivative = new DerivativeImage($params, $src_image);

            if ($derivative->same_as_source()) {
                continue;
            }

            $path = rawurldecode($derivative->get_path());

            if (! file_exists($path)) {
                $ch = gt_curl_handle($base_url . $derivative->get_url());
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }
        }

        if ($handles !== []) {
            do {
                curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh);
                }
            } while ($active);

            foreach ($handles as $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code === 200 || $code === 301) {
                    $generated++;
                } else {
                    $skipped++;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($mh);

        gt_emit('progress', [
            'current' => $i + 1,
            'total' => $total,
            'generated' => $generated,
            'skipped' => $skipped,
            'file' => basename($image['path']),
        ]);
    }

    gt_emit('complete', [
        'generated' => $generated,
        'skipped' => $skipped,
        'elapsed' => round(microtime(true) - $phase_start, 1),
    ]);

    exit();
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+

$count_query = 'SELECT COUNT(*) AS cnt FROM images';
[$count_row] = $conf->sql_backend::query2array($count_query);
$total_count = (int) $count_row['cnt'];

$template->set_filenames([
    'generate_thumbnails' => 'generate_thumbnails.tpl',
]);
$template->assign([
    'ADMIN_PAGE_TITLE' => functions::l10n('Generate thumbnails'),
    'TOTAL_COUNT' => $total_count,
    'U_ACTION' => functions_url::get_root_url() . 'admin.php?page=generate_thumbnails',
]);

$page_data = [
    'gtStrings' => [
        'unexpected_end' => functions::l10n('Server process ended unexpectedly. Check PHP error log for details.'),
        'connection_lost' => functions::l10n('The connection to the server was lost.'),
        'try_again' => functions::l10n('Try again'),
        'aborted' => functions::l10n('Aborted'),
        'aborted_message' => functions::l10n('Aborted. Any thumbnails already generated are saved.'),
        'back' => functions::l10n('Back'),
        'generating' => functions::l10n('Generating thumbnails'),
        'checking' => functions::l10n('Checking and generating missing derivatives'),
        'generated' => functions::l10n('generated'),
        'skipped' => functions::l10n('skipped'),
        'done' => functions::l10n('Done'),
        'thumbnails_generated' => functions::l10n('derivatives generated'),
        'skipped_reason' => functions::l10n('skipped (could not be generated)'),
        'error' => functions::l10n('Error'),
        'run_again' => functions::l10n('Run again'),
    ],
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['generate_thumbnails']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'generate_thumbnails');
