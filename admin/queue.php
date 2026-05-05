<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Job\MessengerFactory;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Symfony\Component\Messenger\MessageBusInterface;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

fs_quick_check();

global $template;

$tableName = Config::dbPrefix() . 'messenger_messages';
$conn      = ServiceLocator::get(Connection::class);

// ---- Actions -------------------------------------------------------------

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

if ($action === 'retry' && is_numeric($_GET['id'] ?? null)) {
    $failedId = (int) $_GET['id'];
    $row      = $conn->executeQuery(
        'SELECT body, headers FROM ' . $tableName . ' WHERE id = ? AND queue_name = ?',
        [$failedId, 'piwigo_failed']
    )->fetchAssociative();

    if ($row !== false) {
        $conn->executeStatement(
            'UPDATE ' . $tableName . ' SET queue_name = ?, available_at = NOW(), delivered_at = NULL WHERE id = ?',
            ['piwigo_async', $failedId]
        );
        PageState::current()->addInfo('Job moved back to async queue.');
    }

    redirect(get_root_url() . 'admin.php?page=queue');
}

if ($action === 'purge_failed') {
    check_pwg_token();
    $conn->executeStatement(
        'DELETE FROM ' . $tableName . ' WHERE queue_name = ?',
        ['piwigo_failed']
    );
    PageState::current()->addInfo('Failed queue purged.');
    redirect(get_root_url() . 'admin.php?page=queue');
}

// ---- Read queue stats ----------------------------------------------------

$stats        = [];
$failedJobs   = [];
$tableExists  = false;

try {
    $rows = $conn->executeQuery(
        'SELECT queue_name, COUNT(*) AS cnt FROM ' . $tableName . ' WHERE delivered_at IS NULL GROUP BY queue_name'
    )->fetchAllAssociative();

    $tableExists = true;

    foreach ($rows as $row) {
        $queueName          = is_string($row['queue_name']) ? $row['queue_name'] : '';
        $stats[$queueName]  = is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
    }

    $failedJobs = $conn->executeQuery(
        'SELECT id, body, created_at, available_at FROM ' . $tableName . ' WHERE queue_name = ? ORDER BY id DESC LIMIT 50',
        ['piwigo_failed']
    )->fetchAllAssociative();
} catch (\Throwable) {
    $tableExists = false;
}

// ---- Template ------------------------------------------------------------

$template->set_filenames(['queue' => 'queue.tpl']);

$pwg_token     = get_pwg_token();
$pendingAsync  = $stats['piwigo_async'] ?? 0;
$pendingFailed = $stats['piwigo_failed'] ?? 0;

$failedJobsForTpl = array_map(static function (array $row): array {
    /** @var array<string, mixed> $body */
    $body    = json_decode(is_string($row['body']) ? $row['body'] : '{}', true) ?? [];
    $class   = is_string($body['type'] ?? null) ? basename(str_replace('\\', '/', $body['type'])) : 'Unknown';
    return [
        'id'         => is_numeric($row['id']) ? (int) $row['id'] : 0,
        'class'      => $class,
        'created_at' => is_string($row['created_at']) ? $row['created_at'] : '',
        'U_RETRY'    => get_root_url() . 'admin.php?page=queue&action=retry&id=' . (is_numeric($row['id']) ? (int) $row['id'] : 0),
    ];
}, $failedJobs);

$template->assign([
    'table_exists'    => $tableExists,
    'pending_async'   => $pendingAsync,
    'pending_failed'  => $pendingFailed,
    'failed_jobs'     => $failedJobsForTpl,
    'U_PURGE_FAILED'  => get_root_url() . 'admin.php?page=queue&action=purge_failed&pwg_token=' . $pwg_token,
    'worker_command'  => 'bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M',
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'queue');
