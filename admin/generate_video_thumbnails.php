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
    $like_clauses = array_map(fn ($ext) => "path LIKE '%." . $ext . "'", $video_exts);
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
$like_clauses = array_map(fn ($ext) => "path LIKE '%." . $ext . "'", $video_exts);
$count_query = 'SELECT COUNT(*) AS cnt FROM images WHERE representative_ext IS NULL AND (' . implode(' OR ', $like_clauses) . ')';
[$count_row] = $conf->sql_backend::query2array($count_query);
$pending_count = (int) $count_row['cnt'];

$template->set_filenames(['generate_video_thumbnails' => 'generate_video_thumbnails.tpl']);
$template->assign([
    'ADMIN_PAGE_TITLE' => functions::l10n('Generate video thumbnails'),
    'PENDING_COUNT' => $pending_count,
    'U_ACTION' => functions_url::get_root_url() . 'admin.php?page=generate_video_thumbnails',
]);
$template->assign_var_from_handle('ADMIN_CONTENT', 'generate_video_thumbnails');
