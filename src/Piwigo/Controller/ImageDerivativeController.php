<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\pwg_image;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionUserResolver;

/**
 * The derivative image server -- P23 batch 8f: ported from i.php's ~900
 * top-level lines and 13 free functions; i.php is now a thin entry shell
 * (root-path define + config includes + autoload boundary + dispatch).
 *
 * FAST-BOOTSTRAP CONTRACT (hard requirement, not a transition state): this
 * controller serves derivative images WITHOUT the full common.inc.php
 * request pipeline -- no plugin loading, no user/session bootstrap, no
 * template. Do not route this through RequestBootstrap. This is a
 * request-shape decision (this endpoint genuinely needs none of that),
 * independent of the DB layer: all DB work here goes through one shared
 * DBAL Connection (image/permission/session lookups alike) --
 * previously this file also kept a separate legacy-mysqli connection for
 * the image/permission lookups specifically to avoid the DI-container
 * construction cost on this endpoint's per-request PHP-FPM hot path;
 * that reasoning no longer applies once the app runs under FrankenPHP
 * workers (the container is built once per worker, not per request), so
 * Legacy Coupling Retirement: DI+DBAL migration retargeted those two
 * calls onto the same Connection/QueryBuilder pattern used everywhere
 * else in this codebase.
 *
 * Note on plugin events: the old i.php carried a local no-op
 * trigger_notify() stub so that pwg_image's constructor (which fires
 * 'load_image_library') would not explode on this path. Since P23 batch 7
 * the real dispatch is always available (pwg_image now calls
 * Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify() directly, a
 * plain autoloaded class, not a composer autoload.files free function --
 * the stub had in fact become a fatal "cannot redeclare" collision), and
 * on this fast path no plugins are ever loaded, so the dispatcher has no
 * handlers and the event is a natural no-op. The stub is therefore
 * deleted, not replaced: not firing plugin hooks here is achieved by not
 * registering any, which is the fast-bootstrap design itself.
 *
 * No globals left: $conf, $logger and $prefixeTable used to be (Legacy
 * Coupling Retirement Track A gap-fill batches G2/G5) -- pwg_image and
 * ImageStdParams read Piwigo\Config\Config:: accessors instead of a raw
 * $conf; this controller's own Logger instance is published via
 * Piwigo\Core\CurrentLogger::set() right after construction, the same
 * static accessor image_ext_imagick.php and every other shared consumer
 * read from now; and every real $prefixeTable read here was already the
 * same value as Piwigo\Config\Config::dbPrefix() (both trace back to the
 * same PIWIGO_DB_PREFIX env var / 'piwigo_' default) -- now moot anyway,
 * since Tables::config()/Tables::images() (used by the DBAL queries
 * below) resolve the same prefix internally. PageState's request-wide
 * query counters, previously maintained by every MysqliDb::query() call
 * on this path, are not replicated by the DBAL Connection -- this
 * endpoint never renders a page with the debug footer that stat feeds,
 * so nothing ever read it here. The
 * throwaway $conf_unused/$prefixeTable_unused locals below only exist to
 * satisfy Env::applyEnvToConf()'s by-ref parameters. The
 * rootPath/derivativePath/derivativeExt/derivativeType/coi/srcLocation/
 * srcPath/srcUrl/originalSize/rotationAngle/derivativeParams
 * scratch state below is this controller's own -- never read outside it
 * (this fast-bootstrap path never runs alongside the normal
 * RequestBootstrap flow that populates the unrelated, same-named
 * $page['root_path'] gallery-navigation concept elsewhere) -- so it's
 * private instance state instead of going through PageState at all.
 */
final class ImageDerivativeController
{
    private string $rootPath = '';

    private string $derivativePath = '';

    private string $derivativeExt = '';

    private ?string $derivativeType = null;

    private ?string $coi = null;

    private string $srcLocation = '';

    private string $srcPath = '';

    private string $srcUrl = '';

    private mixed $originalSize = null;

    private int $rotationAngle = 0;

    private ?DerivativeParams $derivativeParams = null;

    public function serve(): void
    {
        // \Piwigo\Config\Config::dataLocation() needs narrowing here specifically (used
        // before Env::applyEnvToConf() below widens $conf_unused's per-key
        // type info again -- see the comment near the Logger construction).
        $data_location = \Piwigo\Config\Config::dataLocation();
        if (! is_string($data_location)) {
            die("Invalid \\Piwigo\Config\Config::dataLocation() configuration: expected a string.");
        }

        Env::loadEnvFile(PHPWG_ROOT_PATH);
        // Env::applyEnvToConf(array &$conf, string &$prefixeTable)'s two
        // by-ref params are both dead here now (Legacy Coupling Retirement
        // Track A gap-fill batch G2/G5: this controller's own $conf/
        // $prefixeTable reads are retargeted onto Piwigo\Config\Config,
        // synced independently a few lines below via
        // ConfigLoader::applyEnvOverrides()) -- kept only to satisfy the
        // by-ref signature.
        $conf_unused = [];
        $prefixeTable_unused = '';
        Env::applyEnvToConf($conf_unused, $prefixeTable_unused);

        // [SEC-33] populates Piwigo\Config\Config, needed below for
        // DbConnection::build() (the SessionRepository lookup backing
        // checkDerivativePermission()) -- this is the same lightweight,
        // side-effect-free two-liner every other domain's bootstrap already
        // runs, not the full common.inc.php pipeline this controller
        // deliberately avoids.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // \Piwigo\Config\Config::dataLocation()/'log_dir'/'db_password' lost their specific
        // string types the same way the common bootstrap's equivalent config
        // reads do (see Piwigo\Bootstrap\RequestBootstrap): Env::
        // applyEnvToConf(array &$conf, ...)'s by-ref `array` parameter erases
        // the per-key type info PHPStan had built up for $conf_unused, so we
        // re-narrow here.
        $log_data_location = \Piwigo\Config\Config::dataLocation();
        $log_dir = \Piwigo\Config\Config::logDir();
        $db_password = \Piwigo\Config\Config::dbPassword();
        if (! is_string($log_data_location) || ! is_string($log_dir) || ! is_string($db_password)) {
            die("Invalid \\Piwigo\Config\Config::dataLocation()/'log_dir'/'db_password' configuration: expected strings.");
        }

        $logger = new Logger([
            'directory' => PHPWG_ROOT_PATH . $log_data_location . $log_dir,
            'severity' => \Piwigo\Config\Config::logLevel(),
            // we use an hashed filename to prevent direct file access, and we salt with
            // the db_password instead of secret_key because the log must be usable in i.php
            // (secret_key is in the database)
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
        ]);
        \Piwigo\Core\CurrentLogger::set($logger);

        $begin = $step = microtime(true);
        $timing = [];
        foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
            $timing[$k] = '';
        }

        // $conf_unused['dblayer']/'db_host'/'db_user'/'db_base' lost their
        // specific string types the same way described above (see the
        // comment near the Logger construction); 'db_password' was already
        // re-narrowed there and is reused as-is since nothing reassigns it
        // in between. DbConnection::build() reads these same Config::
        // accessors internally, so they're not passed explicitly, but the
        // fail-fast friendly-500 guard on a misconfigured install is kept
        // exactly as before rather than letting an invalid config surface
        // as a raw, uncaught Doctrine exception instead.
        $db_host = \Piwigo\Config\Config::dbHost();
        $db_user = \Piwigo\Config\Config::dbUser();
        $db_base = \Piwigo\Config\Config::dbName();
        if (! is_string($db_host) || ! is_string($db_user) || ! is_string($db_base)) {
            $this->ierror("Invalid \\Piwigo\Config\Config::dbHost()/'db_user'/'db_base' configuration: expected strings.", 500);
        }

        $conn = DbConnection::build();
        $imageRepo = new ImageRepository($conn);

        try {
            $configRows = $conn->createQueryBuilder()
                ->select('param', 'value')
                ->from(Tables::config())
                ->where('param IN (:params)')
                ->setParameter('params', ['derivatives', 'disabled_derivatives'], ArrayParameterType::STRING)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Exception $e) {
            $logger->error($e->getMessage(), 'i.php');
            $configRows = [];
        }

        foreach ($configRows as $row) {
            // 'param' is the config table's primary key column (never NULL in
            // practice), but is only provably `mixed` from DBAL's own row
            // type, so an array key still needs narrowing.
            if (! is_string($row['param'])) {
                continue;
            }
            // ImageStdParams::load_from_db() reads Config::derivatives()/
            // Config::disabledDerivatives() (Legacy Coupling Retirement
            // Track A batch A4), not a raw $conf global, so this
            // fast-path mini-bootstrap's own DB load only needs to sync
            // Config::.
            \Piwigo\Config\Config::override($row['param'], $row['value']);
        }
        ImageStdParams::load_from_db();

        // parseRequest() fills these by mutating $this's own properties;
        // returning its result directly (rather than re-reading
        // $this->derivativeParams afterwards) keeps this typed soundly.
        $params = $this->parseRequest();

        $src_mtime = @filemtime($this->srcPath);
        if ($src_mtime === false) {
            $this->ierror('Source not found', 404);
        }

        // [SEC-33] Resolved (and permission-checked) before $need_generate is
        // even computed -- must run before EVERY fast-path exit below
        // (cached-derivative serve or 304 Not Modified), not just the
        // "generate a new derivative" branch. Otherwise a private album's
        // derivative, once generated once, would be served to anyone who
        // knows/guesses the URL on every later request forever, without ever
        // touching the DB again.
        $this->coi = null;
        if (! str_contains($this->srcLocation, '/pwg_representative/')
            && ! str_contains($this->srcLocation, 'themes/')
            && ! str_contains($this->srcLocation, 'plugins/')) {
            try {
                $row = $imageRepo->findByPath($this->srcLocation);
                if ($row === null) {
                    $this->ierror('Db file path not found', 404);
                }

                $image_id = $row['id'];
                $image_id = is_numeric($image_id) ? (int) $image_id : null;
                if ($image_id === null) {
                    $this->ierror('Invalid image id in database', 500);
                }
                $this->checkDerivativePermission($conn, $image_id);

                if (isset($row['width'])) {
                    $this->originalSize = [$row['width'], $row['height']];
                }
                $rowCoi = $row['coi'];
                $this->coi = is_string($rowCoi) ? $rowCoi : null;

                if (! isset($row['rotation'])) {
                    // get_rotation_angle() returns null for "no EXIF
                    // orientation / non-JPEG source" -- get_rotation_code_
                    // from_angle()'s own docblock confirms that means the
                    // same thing as an explicit 0 (no rotation).
                    $this->rotationAngle = pwg_image::get_rotation_angle($this->srcPath) ?? 0;

                    $imageRepo->updateRotation($image_id, pwg_image::get_rotation_code_from_angle($this->rotationAngle));
                } else {
                    // get_rotation_angle_from_code() accepts int|string; the
                    // `rotation` column is a native DBAL int once retargeted
                    // off the legacy driver's own always-string fetch mode --
                    // still guarded explicitly rather than trusted, since
                    // isset() alone doesn't prove the numeric shape.
                    $rotation = $row['rotation'];
                    if (! is_numeric($rotation)) {
                        $this->ierror('Invalid rotation value in database', 500);
                    }
                    $this->rotationAngle = pwg_image::get_rotation_angle_from_code((int) $rotation);
                }
            } catch (\Exception $e) {
                $logger->error($e->getMessage(), 'i.php');
            }
        } else {
            $this->rotationAngle = 0;
        }

        $need_generate = false;
        $derivative_mtime = @filemtime($this->derivativePath);
        if ($derivative_mtime === false or
            $derivative_mtime < $src_mtime or
            $derivative_mtime < $params->last_mod_time) {
            $need_generate = true;
        }

        $expires = false;
        $now = time();
        if (isset($_GET['b'])) {
            $expires = $now + 100;
            header('Cache-control: no-store, max-age=100');
        } elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {// somehow arbitrary - if derivative params or src didn't change for the last 24 hours, we send an expire header for several days
            $expires = $now + 10 * 24 * 3600;
        }

        if (! $need_generate) {
            $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
            if (is_string($if_modified_since)
              and strtotime($if_modified_since) == $derivative_mtime) {// send the last mod time of the file back
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT', true, 304);
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT', true, 304);
                exit;
            }
            $this->sendDerivative($expires);
            exit;
        }

        if (! $this->trySwitchSource($params, $src_mtime) && $params->type == ImageStdParams::CUSTOM) {
            $sharpen = 0;
            foreach (ImageStdParams::get_defined_type_map() as $std_params) {
                $sharpen += $std_params->sharpen;
            }
            $params->sharpen = round($sharpen / count(ImageStdParams::get_defined_type_map()));
        }

        // Same semantics as the old local mkgetdir(): recursive + index.htm
        // protection, plain `false` on failure (the DIE_ON_ERROR flag is
        // masked out -- the fast path has no HtmlRenderer wired, and the
        // failure response must be ierror()'s own 500, not a fatal).
        if (! FilesystemHelper::mkgetdir(
            dirname($this->derivativePath),
            FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR
        )) {
            $this->ierror('dir create error', 500);
        }

        ignore_user_abort(true);
        @set_time_limit(0);

        $image = new pwg_image($this->srcPath);
        $timing['load'] = $this->timeStep($step);

        $changes = 0;

        // rotate
        if ($this->rotationAngle !== 0) {
            $image->rotate($this->rotationAngle);
            $changes++;
            $timing['rotate'] = $this->timeStep($step);
        }

        // Crop & scale
        $o_size = $d_size = [(int) $image->get_width(), (int) $image->get_height()];
        // $crop_rect/$scaled_size are by-ref out-params; pre-declare as null so the
        // call site's argument type matches SizingParams::compute()'s ?ImageRect/
        // ?array parameter types (an undefined variable is otherwise seen as mixed).
        $crop_rect = null;
        $scaled_size = null;
        $params->sizing->compute($o_size, $this->coi, $crop_rect, $scaled_size);
        if ((bool) $crop_rect) {
            $changes++;
            $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
            $timing['crop'] = $this->timeStep($step);
        }

        if ((bool) $scaled_size) {
            $changes++;
            $image->resize($scaled_size[0], $scaled_size[1]);
            $d_size = [(int) $scaled_size[0], (int) $scaled_size[1]];
            $timing['scale'] = $this->timeStep($step);
        }

        if ((bool) $params->sharpen) {
            $changes += $image->sharpen($params->sharpen);
            $timing['sharpen'] = $this->timeStep($step);
        }

        if ($params->will_watermark($d_size)) {
            $wm = ImageStdParams::get_watermark();
            $wm_image = new pwg_image(PHPWG_ROOT_PATH . $wm->file);
            $wm_size = [(int) $wm_image->get_width(), (int) $wm_image->get_height()];
            if ($d_size[0] < $wm_size[0] or $d_size[1] < $wm_size[1]) {
                $wm_scaling_params = SizingParams::classic($d_size[0], $d_size[1]);
                // $tmp/$wm_scaled_size are by-ref out-params; pre-declare as null
                // (see the analogous compute() call above).
                $tmp = null;
                $wm_scaled_size = null;
                $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
                if ($wm_scaled_size === null) {
                    // compute()'s $scale_size out-param is only null when neither
                    // ratio exceeds 1 — impossible here, since we're inside the same
                    // "watermark bigger than destination in some dimension" guard
                    // that condition is derived from. Guard explicitly instead of
                    // asserting: assert() is a no-op in this environment
                    // (zend.assertions=-1) and would silently let a null through to
                    // the array accesses below if the invariant were ever violated.
                    $this->ierror('Internal error: unexpected watermark scaling result', 500);
                }
                $wm_size = $wm_scaled_size;
                $wm_image->resize($wm_scaled_size[0], $wm_scaled_size[1]);
            }
            $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
            $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
            if ($image->compose($wm_image, $x, $y, $wm->opacity)) {
                $changes++;
                if ((bool) $wm->xrepeat || (bool) $wm->yrepeat) {
                    $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
                    $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));

                    for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                        for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                            if (! (bool) $i && ! (bool) $j) {
                                continue;
                            }
                            $x2 = $x + $i * $xpad;
                            $y2 = $y + $j * $ypad;
                            if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] &&
                                $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1]) {
                                if (! $image->compose($wm_image, $x2, $y2, $wm->opacity)) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            $wm_image->destroy();
            $timing['watermark'] = $this->timeStep($step);
        }

        // no change required - redirect to source
        if (! (bool) $changes) {
            header('X-i: No change');
            $this->ierror($this->srcUrl, 301);
        }

        if (\Piwigo\Config\Config::derivativesStripMetadataThreshold() > $d_size[0] * $d_size[1]) {// strip metadata for small images
            $image->strip();
        }

        $compression_quality = ImageStdParams::$quality;

        // for big sizing never go beyond 75 quality
        if (in_array($this->derivativeType, [ImageStdParams::FOUR_XLARGE, ImageStdParams::THREE_XLARGE])) {
            $compression_quality = min(ImageStdParams::$quality, 75);
        }

        $image->write($this->derivativePath);
        $image->destroy();
        @chmod($this->derivativePath, 0644);
        $timing['save'] = $this->timeStep($step);

        $this->sendDerivative($expires);
        $timing['send'] = $this->timeStep($step);

        $timing['total'] = $this->timeStep($begin);

        if ($logger->severity() >= Logger::DEBUG) {
            $logger->debug('', 'i.php', [
                'src_path' => basename($this->srcPath),
                'derivative_path' => basename($this->derivativePath),
                'o_size' => $o_size[0] . ' ' . $o_size[1] . ' ' . ($o_size[0] * $o_size[1]),
                'd_size' => $d_size[0] . ' ' . $d_size[1] . ' ' . ($d_size[0] * $d_size[1]),
                'mem_usage' => function_exists('memory_get_peak_usage') ? round(memory_get_peak_usage() / (1024 * 1024), 1) : '',
                'timing' => $timing,
                'quality' => $compression_quality,
            ]);
        }
    }

    /**
     * [SEC-33] Denies (403), regardless of whether a cached derivative
     * already exists on disk, any request for an image belonging exclusively
     * to categories the current visitor cannot see (session cookie -> 1
     * query for the session's pwg_uid -> 1 query for the already-computed
     * user_cache.forbidden_categories, never recomputing permissions live
     * in this fast path). The visibility decision itself lives in
     * Piwigo\Permission\ImageVisibilityChecker, the session lookup in
     * Piwigo\Session\SessionUserResolver -- both share this controller's
     * one Connection now (previously 2 separate connections: legacy
     * mysqli for the former, a second DBAL connection for the latter).
     */
    private function checkDerivativePermission(Connection $conn, int $imageId): void
    {
        $guestId = \Piwigo\Config\Config::guestId();
        $userId = $guestId;

        $sessionName = \Piwigo\Config\Config::sessionName();
        if (is_string($sessionName) && $sessionName !== '') {
            $cookieValue = $_COOKIE[$sessionName] ?? null;
            if (is_string($cookieValue) && $cookieValue !== '') {
                $resolved = new SessionUserResolver(new SessionRepository($conn))
                    ->resolveLoggedUserId(
                        $cookieValue,
                        (bool) (\Piwigo\Config\Config::sessionUseIpAddress()),
                    );
                if ($resolved !== null) {
                    $userId = $resolved;
                }
            }
        }

        if (! new ImageVisibilityChecker($conn)->isVisibleToUser($imageId, $userId)) {
            $this->ierror('Forbidden', 403);
        }
    }

    private function ierror(string $msg, int $code): never
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        if ($code == 301 || $code == 302) {
            if (ob_get_length() !== false) {
                ob_clean();
            }
            // default url is on html format
            $url = html_entity_decode($msg);
            $logger->debug($code . ' ' . $url, 'i.php', [
                'url' => $_SERVER['REQUEST_URI'],
            ]);
            // [SEC-35] Request-URI/Content-Location echoed the redirect target
            // back as response headers with no purpose beyond the real
            // Location redirect -- dropped, not just cosmetic (the original
            // request path/query never needs to reach the client twice).
            header('Location: ' . $url);
            exit;
        }
        if ($code >= 400) {
            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? null;
            if (! is_string($protocol)) {
                $protocol = 'HTTP/1.0';
            }
            if (($protocol != 'HTTP/1.1') && ($protocol != 'HTTP/1.0')) {
                $protocol = 'HTTP/1.0';
            }

            header("{$protocol} {$code} {$msg}", true, $code);
        }
        // todo improve
        echo $msg;
        $logger->error($code . ' ' . $msg, 'i.php', [
            'url' => $_SERVER['REQUEST_URI'],
        ]);
        exit;
    }

    private function timeStep(float &$step): int
    {
        $tmp = $step;
        $step = microtime(true);
        return intval(1000 * ($step - $tmp));
    }

    /**
     * @param string[] $tokens
     */
    private function parseCustomParams(array $tokens): DerivativeParams
    {
        if (count($tokens) < 1) {
            $this->ierror('Empty array while parsing Sizing', 400);
        }

        $crop = 0;
        $min_size = null;

        $token = array_shift($tokens);
        if ($token[0] == 's') {
            $size = DerivativeUrlCodec::urlToSize(substr($token, 1));
        } elseif ($token[0] == 'e') {
            $crop = 1;
            $size = $min_size = DerivativeUrlCodec::urlToSize(substr($token, 1));
        } else {
            $size = DerivativeUrlCodec::urlToSize($token);
            if (count($tokens) < 2) {
                $this->ierror('Sizing arr', 400);
            }

            $token = array_shift($tokens);
            $crop = DerivativeUrlCodec::charToFraction($token);

            $token = array_shift($tokens);
            if ($token === null) {
                // count($tokens) >= 2 was checked before the two shifts above,
                // so this is unreachable; guard explicitly (assert() is a
                // no-op in this environment, zend.assertions=-1).
                $this->ierror('Sizing arr', 400);
            }
            $min_size = DerivativeUrlCodec::urlToSize($token);
        }
        return new DerivativeParams(new SizingParams($size, $crop, $min_size));
    }

    private function parseRequest(): DerivativeParams
    {
        if (\Piwigo\Config\Config::questionMarkInUrls() === false and
             isset($_SERVER['PATH_INFO']) and ! empty($_SERVER['PATH_INFO'])) {
            $req = $_SERVER['PATH_INFO'];
            // PHPStan types superglobal reads as mixed; PATH_INFO is only ever
            // populated by the web server as a string (verified via the isset()
            // + !empty() guard above), but we still narrow defensively rather
            // than cast.
            $req = is_string($req) ? $req : '';
            $req = str_replace('//', '/', $req);
            $path_count = count(explode('/', $req));
            $this->rootPath = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
        } else {
            $req = $_SERVER['QUERY_STRING'];
            $req = is_string($req) ? $req : '';
            if ((bool) ($pos = strpos($req, '&'))) {
                $req = substr($req, 0, $pos);
            }
            $req = rawurldecode($req);
            $this->rootPath = PHPWG_ROOT_PATH;
        }

        $req = ltrim($req, '/');

        $req_tokens = preg_split('#/+#', $req);
        if ($req_tokens === false) {
            $this->ierror('Invalid request', 400);
        }
        // config_default.inc.php always sets \Piwigo\Config\Config::syncCharsRegex() to a
        // string literal; guard rather than trust a local-config override.
        $sync_chars_regex = \Piwigo\Config\Config::syncCharsRegex();
        if (! is_string($sync_chars_regex)) {
            $this->ierror('Invalid sync_chars_regex configuration', 500);
        }
        foreach ($req_tokens as $token) {
            (bool) preg_match($sync_chars_regex, $token) or $this->ierror('Invalid chars in request', 400);
        }

        $this->derivativePath = PHPWG_ROOT_PATH . Config::derivativeDir() . $req;

        $pos = strrpos($req, '.');
        $pos !== false || $this->ierror('Missing .', 400);
        $ext = substr($req, $pos);
        $this->derivativeExt = $ext;
        $req = substr($req, 0, $pos);

        $pos = strrpos($req, '-');
        $pos !== false || $this->ierror('Missing -', 400);
        $deriv = substr($req, $pos + 1);
        $req = substr($req, 0, $pos);

        $deriv = explode('_', $deriv);
        foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
            if (DerivativeUrlCodec::derivativeToUrl($type) == $deriv[0]) {
                $this->derivativeType = $type;
                $this->derivativeParams = $params;
                break;
            }
        }

        if ($this->derivativeType === null) {
            if (DerivativeUrlCodec::derivativeToUrl(ImageStdParams::CUSTOM) == $deriv[0]) {
                $this->derivativeType = ImageStdParams::CUSTOM;
            } else {
                $this->ierror('Unknown parsing type', 400);
            }
        }
        array_shift($deriv);

        if ($this->derivativeType === ImageStdParams::CUSTOM) {
            $params = $this->derivativeParams = $this->parseCustomParams($deriv);
            ImageStdParams::apply_global($params);

            if ($params->sizing->ideal_size[0] < 20 or $params->sizing->ideal_size[1] < 20) {
                $this->ierror('Invalid size', 400);
            }
            if ($params->sizing->max_crop < 0 or $params->sizing->max_crop > 1) {
                $this->ierror('Invalid crop', 400);
            }

            $key = [];
            $params->add_url_tokens($key);
            $key = implode('_', $key);
            if (! isset(ImageStdParams::$custom[$key])) {
                $this->ierror('Size not allowed', 403);
            }
        }

        if (is_file(PHPWG_ROOT_PATH . $req . $ext)) {
            $req = './' . $req; // will be used to match #iamges.path
        } elseif (is_file(PHPWG_ROOT_PATH . '../' . $req . $ext)) {
            $req = '../' . $req;
        }

        $this->srcLocation = $req . $ext;
        $this->srcPath = PHPWG_ROOT_PATH . $this->srcLocation;
        $this->srcUrl = $this->rootPath . $this->srcLocation;

        // Every non-erroring path above sets $this->derivativeParams itself
        // (either from the ImageStdParams::get_defined_type_map() match or from
        // parseCustomParams()) before reaching this point; guard explicitly
        // rather than trust flow analysis across the foreach/if branches above.
        $derivative_params = $this->derivativeParams;
        if (! $derivative_params instanceof DerivativeParams) {
            $this->ierror('Internal error: unresolved derivative params', 500);
        }
        return $derivative_params;
    }

    private function trySwitchSource(DerivativeParams $params, int $original_mtime): bool
    {
        if ($this->originalSize === null) {
            return false;
        }

        // $this->originalSize is only ever set (see serve()'s flow) from a
        // DB row's width/height columns (native ints under DBAL); guard the
        // shape and coerce rather than trust it regardless.
        $original_size = $this->originalSize;
        if (! is_array($original_size)
            || ! isset($original_size[0], $original_size[1])
            || ! is_numeric($original_size[0])
            || ! is_numeric($original_size[1])) {
            return false;
        }
        $original_size = [(int) $original_size[0], (int) $original_size[1]];

        if ($this->rotationAngle === 90 || $this->rotationAngle === 270) {
            $tmp = $original_size[0];
            $original_size[0] = $original_size[1];
            $original_size[1] = $tmp;
        }
        $dsize = $params->compute_final_size($original_size);

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
                } // a square that requires watermark should not be generated from a larger derivative with watermark, because if the watermark is not centered on the large image, it will be cropped.
                if ($candidate->sizing->max_crop != 0) {
                    continue;
                } // this could be optimized
                if ($candidate_size[0] < $params->sizing->min_size[0] || $candidate_size[1] < $params->sizing->min_size[1]) {
                    continue;
                }
            }
            $candidates[] = $candidate;
        }

        foreach (array_reverse($candidates) as $candidate) {
            $candidate_path = $this->derivativePath;
            $candidate_path = str_replace('-' . DerivativeUrlCodec::derivativeToUrl($params->type), '-' . DerivativeUrlCodec::derivativeToUrl($candidate->type), $candidate_path);
            $candidate_mtime = @filemtime($candidate_path);
            if ($candidate_mtime === false
              || $candidate_mtime < $original_mtime
              || $candidate_mtime < $candidate->last_mod_time) {
                continue;
            }
            $params->use_watermark = false;
            $params->sharpen = min(1, $params->sharpen);
            $this->srcPath = $candidate_path;
            $this->srcUrl = $this->rootPath . substr($candidate_path, strlen(PHPWG_ROOT_PATH));
            $this->rotationAngle = 0;
            return true;
        }
        return false;
    }

    private function sendDerivative(false|int $expires): void
    {
        $derivative_path = $this->derivativePath;

        if (isset($_GET['ajaxload']) and $_GET['ajaxload'] == 'true') {
            echo json_encode([
                'url' => embellish_url(get_absolute_root_url() . $derivative_path),
            ]);
            return;
        }
        $fp = fopen($derivative_path, 'rb');
        if ($fp === false) {
            $this->ierror('Unable to open derivative file', 500);
        }

        $fstat = fstat($fp);
        if ($fstat === false) {
            $this->ierror('Unable to stat derivative file', 500);
        }
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fstat['mtime']) . ' GMT');
        if ($expires !== false) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
        }
        header('Connection: close');

        $derivative_ext = $this->derivativeExt;

        $ctype = 'application/octet-stream';
        switch (strtolower($derivative_ext)) {
            case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg';
                break;
            case '.png': $ctype = 'image/png';
                break;
            case '.gif': $ctype = 'image/gif';
                break;
            case '.webp': $ctype = 'image/webp';
                break;
        }
        header("Content-Type: {$ctype}");

        fpassthru($fp);
        fclose($fp);
    }
}
