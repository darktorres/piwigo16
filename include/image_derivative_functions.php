<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Filesystem;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageDerivativeContext;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

// Stubs for functions that are not loaded in the i.php minimal bootstrap.
// When accessed via the full pipeline (common.inc.php), these are already
// defined in include/functions.inc.php, so the function_exists guards skip them.

if (!function_exists('trigger_notify')) {
    function trigger_notify(string $event, mixed ...$args): void
    {
    }
}
if (!function_exists('trigger_change')) {
    function trigger_change(string $event, mixed ...$args): mixed
    {
        return $args[0] ?? null;
    }
}
if (!function_exists('get_extension')) {
    function get_extension(string $filename): string
    {
        $ext = strrchr($filename, '.');
        return $ext !== false ? substr($ext, 1) : '';
    }
}
if (!function_exists('mkgetdir')) {
    function mkgetdir(string $dir, int $flags = 0): bool
    {
        if (!is_dir($dir)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            set_error_handler(static fn (): bool => true);
            try {
                $mkd = mkdir($dir, Config::chmodValue(), true);
            } finally {
                restore_error_handler();
            }
            umask($umask);
            if ($mkd == false && !is_dir($dir)) {
                return false;
            }
            $file = $dir . '/index.htm';
            if (!file_exists($file)) {
                set_error_handler(static fn (): bool => true);
                try {
                    file_put_contents($file, 'Not allowed!');
                } finally {
                    restore_error_handler();
                }
            }
        }
        if (!is_writable($dir)) {
            return false;
        }
        return true;
    }
}
if (!function_exists('safe_unserialize')) {
    /**
     * @param array<mixed>|string $value
     * @return array<mixed>
     */
    function safe_unserialize(array|string $value): array
    {
        if (is_string($value)) {
            $result = unserialize($value);
            return is_array($result) ? $result : [];
        }
        return $value;
    }
}

// Functions unique to the image derivative pipeline (not in functions.inc.php)

function ierror(string $msg, int $code): never
{
    $logger = LoggerRegistry::current();
    if ($code == 301 || $code == 302) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        $url = html_entity_decode($msg);
        $logger->debug($code . ' ' . $url, ['url' => $_SERVER['REQUEST_URI'] ?? '']);
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit;
    }
    if ($code >= 400) {
        $protocolRaw = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0';
        $protocol    = is_string($protocolRaw) ? $protocolRaw : 'HTTP/1.0';
        if ('HTTP/1.1' != $protocol && 'HTTP/1.0' != $protocol) {
            $protocol = 'HTTP/1.0';
        }
        header($protocol . ' ' . $code . ' ' . $msg, true, $code);
    }
    echo $msg;
    $logger->error($code . ' ' . $msg, ['url' => $_SERVER['REQUEST_URI'] ?? '']);
    exit;
}

function time_step(float &$step): int
{
    $tmp  = $step;
    $step = microtime(true);
    return intval(1000 * ($step - $tmp));
}

/** @return int[] */
function url_to_size(string $s): array
{
    $pos = strpos($s, 'x');
    if ($pos === false) {
        return [(int) $s, (int) $s];
    }
    return [(int) substr($s, 0, $pos), (int) substr($s, $pos + 1)];
}

/** @param string[] $tokens */
function parse_custom_params(array $tokens): DerivativeParams
{
    if (count($tokens) < 1) {
        ierror('Empty array while parsing Sizing', 400);
    }

    $crop     = 0;
    $min_size = null;
    $token    = array_shift($tokens);

    if ($token[0] == 's') {
        $size = url_to_size(substr($token, 1));
    } elseif ($token[0] == 'e') {
        $crop     = 1;
        $size     = $min_size = url_to_size(substr($token, 1));
    } else {
        $size = url_to_size($token);
        if (count($tokens) < 2) {
            ierror('Sizing arr', 400);
        }
        $token    = array_shift($tokens);
        $crop     = char_to_fraction($token);
        $token    = array_shift($tokens);
        $min_size = url_to_size($token ?? '');
    }
    return new DerivativeParams(new SizingParams($size, $crop, $min_size));
}

function parse_request(ImageDerivativeContext $ctx): DerivativeParams
{
    if (Config::questionMarkInUrls() == false
        && isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])
    ) {
        $req = is_scalar($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
        $req = str_replace('//', '/', $req);
        $path_count  = count(explode('/', $req));
        $ctx->rootPath = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
    } else {
        $req = is_scalar($_SERVER['QUERY_STRING'] ?? null) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($pos = strpos($req, '&')) {
            $req = substr($req, 0, $pos);
        }
        $req          = rawurldecode($req);
        $ctx->rootPath = PHPWG_ROOT_PATH;
    }

    $req = ltrim($req, '/');

    foreach (preg_split('#/+#', $req) ?: [] as $token) {
        preg_match(Config::syncCharsRegex(), $token) or ierror('Invalid chars in request', 400);
    }

    $ctx->derivativePath = PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $req;

    $pos = strrpos($req, '.');
    $pos !== false || ierror('Missing .', 400);
    $ext            = substr($req, $pos);
    $ctx->derivativeExt = $ext;
    $req            = substr($req, 0, $pos);

    $pos = strrpos($req, '-');
    $pos !== false || ierror('Missing -', 400);
    $deriv = substr($req, $pos + 1);
    $req   = substr($req, 0, $pos);
    $deriv = explode('_', $deriv);

    foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
        if (derivative_to_url($type) == $deriv[0]) {
            $ctx->derivativeType   = $type;
            $ctx->derivativeParams = $params;
            break;
        }
    }

    if ($ctx->derivativeType === null) {
        if (derivative_to_url(IMG_CUSTOM) == $deriv[0]) {
            $ctx->derivativeType = IMG_CUSTOM;
        } else {
            ierror('Unknown parsing type', 400);
        }
    }
    array_shift($deriv);

    if ($ctx->derivativeType == IMG_CUSTOM) {
        $params = $ctx->derivativeParams = parse_custom_params($deriv);
        ImageStdParams::apply_global($params);

        if ($params->sizing->ideal_size[0] < 20 || $params->sizing->ideal_size[1] < 20) {
            ierror('Invalid size', 400);
        }
        if ($params->sizing->max_crop < 0 || $params->sizing->max_crop > 1) {
            ierror('Invalid crop', 400);
        }
        $key = [];
        $params->add_url_tokens($key);
        $key = implode('_', $key);
        if (!isset(ImageStdParams::$custom[$key])) {
            ierror('Size not allowed', 403);
        }
    }

    if (is_file(PHPWG_ROOT_PATH . $req . $ext)) {
        $req = './' . $req;
    } elseif (is_file(PHPWG_ROOT_PATH . '../' . $req . $ext)) {
        $req = '../' . $req;
    }

    $ctx->srcLocation = $req . $ext;
    $ctx->srcPath     = PHPWG_ROOT_PATH . $ctx->srcLocation;
    $ctx->srcUrl      = $ctx->rootPath . $ctx->srcLocation;

    $dp = $ctx->derivativeParams;
    if (!($dp instanceof DerivativeParams)) {
        ierror('Invalid derivative params', 400);
    }
    return $dp;
}

function try_switch_source(DerivativeParams $params, ?int $original_mtime, ImageDerivativeContext $ctx): bool
{
    if ($ctx->originalSize === null) {
        return false;
    }
    $original_size = $ctx->originalSize;
    if ($ctx->rotationAngle == 90 || $ctx->rotationAngle == 270) {
        [$original_size[0], $original_size[1]] = [$original_size[1], $original_size[0]];
    }
    $dsize         = $params->compute_final_size($original_size);
    $use_watermark = $params->use_watermark;
    if ($use_watermark) {
        $use_watermark = $params->will_watermark($dsize);
    }
    $candidates = [];
    foreach (ImageStdParams::get_defined_type_map() as $candidate) {
        if ($candidate->type == $params->type) {
            continue;
        }
        if ($candidate->use_watermark != $use_watermark) {
            continue;
        }
        if ($candidate->max_width() < $params->max_width() || $candidate->max_height() < $params->max_height()) {
            continue;
        }
        $candidate_size = $candidate->compute_final_size($original_size);
        if ($dsize != $params->compute_final_size($candidate_size)) {
            continue;
        }
        if ($params->sizing->max_crop == 0) {
            if ($candidate->sizing->max_crop != 0) {
                continue;
            }
        } else {
            if ($use_watermark && $candidate->use_watermark) {
                continue;
            }
            if ($candidate->sizing->max_crop != 0) {
                continue;
            }
            if ($candidate_size[0] < $params->sizing->min_size[0] || $candidate_size[1] < $params->sizing->min_size[1]) {
                continue;
            }
        }
        $candidates[] = $candidate;
    }
    foreach (array_reverse($candidates) as $candidate) {
        $candidate_path  = str_replace('-' . derivative_to_url($params->type), '-' . derivative_to_url($candidate->type), $ctx->derivativePath);
        $candidate_mtime = Filesystem::tryFileMtime($candidate_path);
        if ($candidate_mtime === false
            || $candidate_mtime < $original_mtime
            || $candidate_mtime < $candidate->last_mod_time
        ) {
            continue;
        }
        $params->use_watermark = false;
        $params->sharpen       = min(1, $params->sharpen);
        $ctx->srcPath          = $candidate_path;
        $ctx->srcUrl           = $ctx->rootPath . substr($candidate_path, strlen(PHPWG_ROOT_PATH));
        $ctx->rotationAngle    = 0;
        return true;
    }
    return false;
}

function send_derivative(int|false $expires, ImageDerivativeContext $ctx): void
{
    if (isset($_GET['ajaxload']) && $_GET['ajaxload'] == 'true') {
        require_once PHPWG_ROOT_PATH . 'include/functions_cookie.inc.php';
        require_once PHPWG_ROOT_PATH . 'include/functions_url.inc.php';
        echo json_encode(['url' => embellish_url(get_absolute_root_url() . $ctx->derivativePath)]);
        return;
    }
    $fp = fopen($ctx->derivativePath, 'rb');
    if ($fp === false) {
        ierror('Cannot open derivative file', 500);
    }
    $fstat = fstat($fp);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fstat !== false ? $fstat['mtime'] : 0) . ' GMT');
    if ($expires !== false) {
        header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
    }
    header('Connection: close');
    $ctype = 'application/octet-stream';
    switch (strtolower($ctx->derivativeExt)) {
        case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg'; break;
        case '.png':  $ctype = 'image/png';  break;
        case '.gif':  $ctype = 'image/gif';  break;
        case '.webp': $ctype = 'image/webp'; break;
    }
    header('Content-Type: ' . $ctype);
    fpassthru($fp);
    fclose($fp);
}
