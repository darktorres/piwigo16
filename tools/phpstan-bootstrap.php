<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');
define('PHPWG_VERSION', '16.3.0');
define('PWG_LOCAL_DIR', 'local/');
define('PHPWG_INSTALLED', true);
define('IN_ADMIN', false);

/** @var array<string,mixed> $conf */
$conf = [];
/** @var array<string,mixed> $user */
$user = [];
/** @var array{infos:list<string>,errors:list<string>,warnings:list<string>,messages:list<string>,body_classes:list<string>,body_data:array<string,mixed>} $page */
$page = ['infos' => [], 'errors' => [], 'warnings' => [], 'messages' => [], 'body_classes' => [], 'body_data' => []];
/** @var array<string,string> $lang */
$lang = [];
/** @var \Piwigo\Template\Template|null $template */
$template = null;
/** @var \Piwigo\Core\Logger|null $logger */
$logger = null;
/** @var \mysqli|null $mysqli */
$mysqli = null;
