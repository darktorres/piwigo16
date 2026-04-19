<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_upload;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

$sse_mode = isset($_GET['sse']);

function vt_emit(string $event, array $data): void
{
    if (! $GLOBALS['sse_mode']) {
        return;
    }

    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
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

    $video_exts = ['wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg', 'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv'];
    $like_clauses = array_map(fn (string $ext): string => "path LIKE '%." . $ext . "'", $video_exts);
    $query = 'SELECT id, path FROM images WHERE representative_ext IS NULL AND (' . implode(' OR ', $like_clauses) . ')';
    $images = $conf->sql_backend::query2array($query);
    $total = count($images);

    if ($total === 0) {
        vt_emit('complete', ['generated' => 0, 'skipped' => 0, 'elapsed' => 0]);
        exit();
    }

    vt_emit('start', ['total' => $total]);

    $generated = 0;
    $skipped = 0;
    $phase_start = microtime(true);

    foreach ($images as $i => $image) {
        $full_path = PHPWG_ROOT_PATH . ltrim($image['path'], './');
        $skip_reason = null;

        $ffmpeg_output = [];

        if (! file_exists($full_path)) {
            $skipped++;
            $skip_reason = 'file_not_found';
        } else {
            $new_ext = functions_upload::upload_file_video(null, $full_path, $ffmpeg_output);

            if ($new_ext !== null) {
                $update_query = "UPDATE images SET representative_ext = 'jpg' WHERE id = {$image['id']}";
                $conf->sql_backend::pwg_query($update_query);
                $generated++;
            } else {
                $skipped++;
                $skip_reason = 'ffmpeg_failed';
            }
        }

        $event = [
            'current' => $i + 1,
            'total' => $total,
            'generated' => $generated,
            'skipped' => $skipped,
            'file' => basename($image['path']),
            'skip_reason' => $skip_reason,
        ];

        if ($ffmpeg_output !== []) {
            $event['ffmpeg_output'] = $ffmpeg_output;
        }

        vt_emit('progress', $event);
    }

    vt_emit('complete', [
        'generated' => $generated,
        'skipped' => $skipped,
        'elapsed' => round(microtime(true) - $phase_start, 1),
    ]);

    exit();
}

// +-----------------------------------------------------------------------+
// | template initialization                                               |
// +-----------------------------------------------------------------------+

$video_exts = ['wmv', 'mov', 'mkv', 'mp4', 'mpg', 'flv', 'asf', 'xvid', 'divx', 'mpeg', 'avi', 'rm', 'm4v', 'ogg', 'ogv', 'webm', 'webmv'];
$like_clauses = array_map(fn (string $ext): string => "path LIKE '%." . $ext . "'", $video_exts);
$count_query = 'SELECT COUNT(*) AS cnt FROM images WHERE representative_ext IS NULL AND (' . implode(' OR ', $like_clauses) . ')';
[$count_row] = $conf->sql_backend::query2array($count_query);
$pending_count = (int) $count_row['cnt'];

$template->set_filenames(['generate_video_thumbnails' => 'generate_video_thumbnails.tpl']);
$template->assign([
    'ADMIN_PAGE_TITLE' => functions::l10n('Generate video thumbnails'),
    'PENDING_COUNT' => $pending_count,
    'U_ACTION' => functions_url::get_root_url() . 'admin.php?page=generate_video_thumbnails',
]);

$page_data = [
    'vtStrings' => [
        'unexpected_end' => functions::l10n('Server process ended unexpectedly. Check PHP error log for details.'),
        'connection_lost' => functions::l10n('The connection to the server was lost.'),
        'try_again' => functions::l10n('Try again'),
        'aborted' => functions::l10n('Aborted'),
        'aborted_message' => functions::l10n('Aborted. Any thumbnails already generated are saved.'),
        'back' => functions::l10n('Back'),
        'generating' => functions::l10n('Generating video thumbnails'),
        'extracting' => functions::l10n('Extracting frames with FFmpeg'),
        'generated' => functions::l10n('generated'),
        'skipped' => functions::l10n('skipped'),
        'done' => functions::l10n('Done'),
        'file_not_found' => functions::l10n('File not found on disk'),
        'ffmpeg_output' => functions::l10n('FFmpeg output'),
        'ffmpeg_no_output' => functions::l10n('FFmpeg produced no output'),
        'thumbnails_generated' => functions::l10n('thumbnails generated'),
        'skipped_reason' => functions::l10n('skipped (file not found or FFmpeg unavailable)'),
        'error' => functions::l10n('Error'),
        'run_again' => functions::l10n('Run again'),
    ],
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['generate_video_thumbnails']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'generate_video_thumbnails');
