<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Url\UrlService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The derivative image server -- P23 batch 8f: ported from i.php's ~900
 * top-level lines and 13 free functions; i.php is now a thin entry shell
 * (Paths::fromRoot() + RequestBootstrap::bootEntryPoint() +
 * RequestPipeline::handle() dispatch, matching every other P22/P23 root
 * file's own shape).
 *
 * This controller now runs through the same bootstrap as every other
 * frontend controller -- RequestBootstrap::bootEntryPoint()'s configure()/
 * connect()/finalize() chain (plugin loading, native $_SESSION bootstrap,
 * full user resolution, template construction) has already run by the time
 * ControllerInvokerMiddleware invokes __invoke() below, so this class no
 * longer duplicates any of that: Config/CurrentLogger/CurrentUser are
 * already populated, and the permission check reads the already-resolved
 * CurrentUser instead of re-deriving it from the session cookie itself
 * (see checkDerivativePermission()'s own docblock).
 *
 * All DB work here goes through one shared DBAL Connection
 * (image/permission lookups alike) -- previously this file also
 * kept a separate legacy-mysqli connection for the image/permission
 * lookups specifically to avoid the DI-container construction cost on
 * this endpoint's per-request PHP-FPM hot path; that reasoning no longer
 * applies once the app runs under FrankenPHP workers (the container is
 * built once per worker, not per request), so Legacy Coupling Retirement:
 * DI+DBAL migration retargeted those two calls onto the same
 * Connection/QueryBuilder pattern used everywhere else in this codebase.
 *
 * No globals left: $conf, $logger and $prefixeTable used to be (Legacy
 * Coupling Retirement Track A gap-fill batches G2/G5) -- PwgImage and
 * ImageStdParams read Piwigo\Config\Config:: accessors instead of a raw
 * $conf; this controller's own logger is Piwigo\Core\CurrentLogger::get(),
 * the same static accessor ImageExtImagick.php and every other shared
 * consumer read from now; and every real $prefixeTable read here was
 * already the same value as Piwigo\Db\DbCredentials::current()->prefix
 * (both trace back to the same PIWIGO_DB_PREFIX env var / 'piwigo_'
 * default) -- now
 * moot anyway, since Tables::config()/Tables::images() resolve the same
 * prefix internally. PageState's request-wide query counters, previously
 * maintained by every MysqliDb::query() call on this path, are not
 * replicated by the DBAL Connection -- this endpoint never renders a page
 * with the debug footer that stat feeds, so nothing ever read it here. The
 * derivativePath/derivativeUrlSuffix/derivativeExt/derivativeType/coi/
 * srcLocation/srcPath/srcUrl/originalSize/rotationAngle/derivativeParams
 * scratch state below is this controller's own -- never read outside it
 * (unrelated to the same-named $page['root_path'] gallery-navigation
 * concept elsewhere) -- so it's private instance state instead of going
 * through PageState at all.
 */
final class ImageDerivativeController implements ControllerInterface
{
    private string $derivativePath = '';

    /**
     * The raw request string (PATH_INFO or QUERY_STRING, whichever
     * derivativeUrlStyle the site uses), captured before parseRequest()
     * mutates $req -- exactly the URL suffix that follows `i.php?/` (or
     * `i.php/`), reused by trySwitchSource() to build a redirect to a
     * *different* already-cached derivative type via the same
     * permission-checked i.php path, not a raw filesystem link.
     */
    private string $derivativeUrlSuffix = '';

    private string $derivativeExt = '';

    private ?string $derivativeType = null;

    private ?string $coi = null;

    private string $srcLocation = '';

    private string $srcPath = '';

    private string $srcUrl = '';

    /**
     * @var ?array{int, ?int} width is guaranteed int once set (see the
     *   $row->width !== null guard in __invoke()'s own flow), but height can
     *   still independently be null (both are separately-nullable DB
     *   columns).
     */
    private ?array $originalSize = null;

    private int $rotationAngle = 0;

    private ?DerivativeParams $derivativeParams = null;

    public function __construct(
        private readonly Paths $paths,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly \Piwigo\PluginConfig\EventDispatcher $eventDispatcher,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Config/ImageStdParams/CurrentLogger are already populated by
        // RequestBootstrap::bootEntryPoint() (called from i.php before this
        // controller is ever reached) -- ConfigService::loadConfFromDb()
        // covers the 'derivatives'/'disabled_derivatives' keys this
        // controller used to fetch itself, and connect() already calls
        // ImageStdParams::load_from_db() and builds the same
        // hashed-filename Logger this used to construct a second time.
        $logger = $this->currentLogger->get();

        $begin = $step = microtime(true);
        $timing = [];
        foreach (explode(',', 'load,rotate,crop,scale,sharpen,watermark,save,send') as $k) {
            $timing[$k] = '';
        }

        $conn = DbConnection::build();
        $imageRepo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class);

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
        // Part II (web-root isolation): populated only in the real-DB-image
        // branch below, read later by the "redirect to source" fast path
        // (~line 432) to build a permission-checked action.php?part=e
        // redirect instead of a raw link into upload/ -- see that call
        // site's own comment for the real bug this fixes.
        $image_id = null;
        $this->coi = null;
        if (! str_contains($this->srcLocation, '/pwg_representative/')
            && ! str_contains($this->srcLocation, 'themes/')
            && ! str_contains($this->srcLocation, 'plugins/')) {
            try {
                $row = $imageRepo->findByPath($this->srcLocation);
                if ($row === null) {
                    $this->ierror('Db file path not found', 404);
                }

                $image_id = $row->id;
                $this->checkDerivativePermission($conn, $image_id);

                if ($row->width !== null) {
                    $this->originalSize = [$row->width, $row->height];
                }
                $this->coi = $row->coi;

                if ($row->rotation === null) {
                    // get_rotation_angle() returns null for "no EXIF
                    // orientation / non-JPEG source" -- get_rotation_code_
                    // from_angle()'s own docblock confirms that means the
                    // same thing as an explicit 0 (no rotation).
                    $this->rotationAngle = PwgImage::get_rotation_angle($this->srcPath) ?? 0;

                    $imageRepo->updateRotation($image_id, PwgImage::get_rotation_code_from_angle($this->rotationAngle));
                } else {
                    $this->rotationAngle = PwgImage::get_rotation_angle_from_code($row->rotation);
                }
            } catch (ResponseReadyException $e) {
                // Part III: a real, security-critical regression this catch
                // block would otherwise cause -- ierror()/
                // checkDerivativePermission() above now throw instead of
                // exit()ing (the whole reason exit() was safe inside this
                // try/catch and a throw is not: exit() bypasses any
                // enclosing catch entirely, a throw does not). Without this
                // catch-and-rethrow, the generic catch (\Exception) below
                // would silently swallow a real 403 Forbidden/404/500 and
                // let this method continue as if nothing happened --
                // confirmed live, a real anonymous request for a private
                // album's derivative was served instead of denied.
                throw $e;
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
        // Applied to whichever Response one of this method's 3 return
        // points below ends up building (was a bare header() call,
        // unconditionally queued regardless of which branch ran next).
        $noStoreCacheControl = false;
        if (Request\ImageDerivativeRequest::fromGlobals()->hasCacheBust) {
            $expires = $now + 100;
            $noStoreCacheControl = true;
        } elseif ($now > (max($src_mtime, $params->last_mod_time) + 24 * 3600)) {// somehow arbitrary - if derivative params or src didn't change for the last 24 hours, we send an expire header for several days
            $expires = $now + 10 * 24 * 3600;
        }

        if (! $need_generate) {
            $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
            if (is_string($if_modified_since)
              and strtotime($if_modified_since) === $derivative_mtime) {// send the last mod time of the file back
                $response = ResponseFactory::raw('', [
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $derivative_mtime) . ' GMT',
                    'Expires' => gmdate('D, d M Y H:i:s', time() + 10 * 24 * 3600) . ' GMT',
                ], 304);

                return $noStoreCacheControl ? $response->withHeader('Cache-Control', 'no-store, max-age=100') : $response;
            }
            $response = $this->sendDerivative($expires);

            return $noStoreCacheControl ? $response->withHeader('Cache-Control', 'no-store, max-age=100') : $response;
        }

        if (! $this->trySwitchSource($params, $src_mtime) && $params->type === ImageStdParams::CUSTOM) {
            $sharpen = 0.0;
            foreach (ImageStdParams::get_defined_type_map() as $std_params) {
                $sharpen += $std_params->sharpen;
            }
            $params->sharpen = round($sharpen / (float) count(ImageStdParams::get_defined_type_map()));
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

        $image = new PwgImage($this->srcPath, $this->currentLogger, $this->eventDispatcher);
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
            if ($image->sharpen($params->sharpen)) {
                $changes++;
            }
            $timing['sharpen'] = $this->timeStep($step);
        }

        if ($params->will_watermark($d_size)) {
            $wm = ImageStdParams::get_watermark();
            $wm_image = new PwgImage($this->paths->root . $wm->file, $this->currentLogger, $this->eventDispatcher);
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
            $x = round(((float) $wm->xpos / 100.0) * ((float) $d_size[0] - (float) $wm_size[0]));
            $y = round(((float) $wm->ypos / 100.0) * ((float) $d_size[1] - (float) $wm_size[1]));
            if ($image->compose($wm_image, $x, $y, $wm->opacity)) {
                $changes++;
                if ((bool) $wm->xrepeat || (bool) $wm->yrepeat) {
                    $xpad = (float) $wm_size[0] + (float) max(30, round((float) $wm_size[0] / 4.0));
                    $ypad = (float) $wm_size[1] + (float) max(30, round((float) $wm_size[1] / 4.0));

                    for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++) {
                        for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++) {
                            if (! (bool) $i && ! (bool) $j) {
                                continue;
                            }
                            $x2 = $x + (float) $i * $xpad;
                            $y2 = $y + (float) $j * $ypad;
                            if ($x2 >= 0 && $x2 + (float) $wm_size[0] < $d_size[0] &&
                                $y2 >= 0 && $y2 + (float) $wm_size[1] < $d_size[1]) {
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
            // Part II/III (web-root isolation): $this->srcUrl used to be a
            // raw link into upload/ (via PHPWG_ROOT_PATH, now fully retired
            // from this class -- see parseRequest()'s/trySwitchSource()'s
            // own comments) -- upload/ and _data/i/ are both deliberately
            // unreachable now (SEC-33/35/38/47), so that redirect target
            // 404d. Found live (a real Visual Regression failure, decoded
            // and diffed rather than dismissed): every derivative whose
            // computed size is identity to the source hits this exact
            // path, not a rare edge case. $image_id is only set for a real
            // DB-backed image (not the theme/plugin/representative
            // branches above, which keep the unchanged raw $this->srcUrl
            // link -- themes/ is a real, safe, reachable symlink); when
            // set, redirect through action.php?part=e instead, the same
            // permission-checked path Controller\ActionController's own
            // 'e' case already re-verifies (including the HD-access check
            // that a raw upload/ link also silently bypassed). $this->srcUrl
            // itself may also already be an i.php?/... redirect to a
            // *different* already-cached derivative type (trySwitchSource()'s
            // own permission-checked redirect, see its own comment).
            if ($image_id !== null) {
                $this->ierror(\Piwigo\Bootstrap\PresentationAccessor::urlService()->getActionUrl($image_id, 'e', false), 301, [
                    'X-i' => 'No change',
                ]);
            }
            $this->ierror($this->srcUrl, 301, [
                'X-i' => 'No change',
            ]);
        }

        if (\Piwigo\Config\CurrentConfig::derivativesStripMetadataThreshold() > $d_size[0] * $d_size[1]) {// strip metadata for small images
            $image->strip();
        }

        $compression_quality = ImageStdParams::$quality;

        // for big sizing never go beyond 75 quality
        if (in_array($this->derivativeType, [ImageStdParams::FOUR_XLARGE, ImageStdParams::THREE_XLARGE], true)) {
            $compression_quality = min(ImageStdParams::$quality, 75);
        }

        $image->write($this->derivativePath);
        $image->destroy();
        @chmod($this->derivativePath, 0644);
        $timing['save'] = $this->timeStep($step);

        $response = $this->sendDerivative($expires);
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

        return $noStoreCacheControl ? $response->withHeader('Cache-Control', 'no-store, max-age=100') : $response;
    }

    /**
     * [SEC-33] Denies (403), regardless of whether a cached derivative
     * already exists on disk, any request for an image belonging exclusively
     * to categories the current visitor cannot see -- a plain
     * CurrentUser::forbiddenCategories property read, never recomputing
     * permissions live (gap-closure Stage 4g: no query at all any more, see
     * ImageVisibilityChecker's own docblock). The visibility decision itself
     * lives in Piwigo\Permission\ImageVisibilityChecker; CurrentUser is
     * already resolved by RequestBootstrap::connect() ->
     * UserBootstrap::initialize() (cookie/auto-login/Apache-auth/auth-key,
     * guest id when none apply) before this controller ever runs -- no
     * separate session-cookie lookup needed here any more.
     */
    private function checkDerivativePermission(Connection $conn, int $imageId): void
    {
        if (! \Piwigo\Bootstrap\CoreDomainAccessor::imageVisibilityChecker()->isVisibleToUser($imageId)) {
            $this->ierror('Forbidden', 403);
        }
    }

    /**
     * Part III (real routing pipeline): throws instead of header()+echo()+
     * exit()ing directly -- a throw satisfies this method's own `: never`
     * return type exactly like exit() did, so every one of this class's
     * ~25 call sites (some several frames deep, e.g. parseRequest()/
     * parseCustomParams()/checkDerivativePermission()) needed zero changes,
     * the same zero-call-site-ripple mechanism Workstream C3 already
     * established for RedirectService/HtmlService. Caught by
     * ControllerInvokerMiddleware's own catch point, since this class is
     * now a real, routed ControllerInterface -- no new catch point needed.
     *
     * @param array<string, string> $extraHeaders only ever used by the one
     *   redirect call site that used to queue an extra diagnostic header()
     *   call of its own right before calling this method -- a raw header()
     *   call here would never reach the client now (nothing echoes the
     *   response directly any more, ResponseEmitter only emits whatever's
     *   actually attached to the Response object this throws).
     */
    private function ierror(string $msg, int $code, array $extraHeaders = []): never
    {
        $logger = $this->currentLogger->get();
        if ($code === 301 || $code === 302) {
            // default url is on html format
            $url = html_entity_decode($msg);
            $logger->debug($code . ' ' . $url, 'i.php', [
                'url' => $_SERVER['REQUEST_URI'] ?? null,
            ]);
            // [SEC-35] Request-URI/Content-Location echoed the redirect target
            // back as response headers with no purpose beyond the real
            // Location redirect -- dropped, not just cosmetic (the original
            // request path/query never needs to reach the client twice).
            $response = ResponseFactory::redirect($url, $code);
            foreach ($extraHeaders as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            throw new ResponseReadyException($response);
        }

        $logger->error($code . ' ' . $msg, 'i.php', [
            'url' => $_SERVER['REQUEST_URI'] ?? null,
        ]);
        throw new ResponseReadyException(ResponseFactory::text($msg, $code));
    }

    private function timeStep(float &$step): int
    {
        $tmp = $step;
        $step = microtime(true);
        return intval(1000.0 * ($step - $tmp));
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
        if ($token[0] === 's') {
            $size = DerivativeUrlCodec::urlToSize(substr($token, 1));
        } elseif ($token[0] === 'e') {
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
        // Part III (real routing pipeline): $this->srcUrl (a real 301
        // redirect target for the theme/plugin/representative fallback --
        // see the "no change required" branch's own comment for the real
        // DB-image case, already routed through action.php) used to be
        // built from a PHPWG_ROOT_PATH-relative prefix, with the PATH_INFO
        // branch below computing how many '../' to prepend based on the
        // *request's own* apparent URL depth (i.php/upload/2026/08/01/...
        // looks 4 directories deep from the browser's own relative-URL
        // resolution, even though i.php itself is a top-level entry file)
        // -- genuinely fragile (get the depth count wrong and the redirect
        // 404s or lands somewhere else entirely), and PHPWG_ROOT_PATH is
        // gone from every other entry file (Part 0). UrlService::
        // getAbsoluteRootUrl(false) (no scheme) resolves the app's real
        // mount path from cookiePath() -- SCRIPT_NAME/REDIRECT_URL-based,
        // already the established, request-depth-independent mechanism
        // this exact class's own CookieService dependency uses elsewhere in
        // this codebase -- so building $this->srcUrl from it needs no
        // depth computation of any kind, in either URL style below.
        if (\Piwigo\Config\CurrentConfig::questionMarkInUrls() === false and
             isset($_SERVER['PATH_INFO']) and ! in_array($_SERVER['PATH_INFO'], [null, false, 0, '0', '', []], true)) {
            $req = $_SERVER['PATH_INFO'];
            // PHPStan types superglobal reads as mixed; PATH_INFO is only ever
            // populated by the web server as a string (verified via the isset()
            // + !empty() guard above), but we still narrow defensively rather
            // than cast.
            $req = is_string($req) ? $req : '';
            $req = str_replace('//', '/', $req);
        } else {
            $req = $_SERVER['QUERY_STRING'] ?? null;
            $req = is_string($req) ? $req : '';
            if ((bool) ($pos = strpos($req, '&'))) {
                $req = substr($req, 0, $pos);
            }
            $req = rawurldecode($req);
        }

        $req = ltrim($req, '/');

        $req_tokens = preg_split('#/+#', $req);
        if ($req_tokens === false) {
            $this->ierror('Invalid request', 400);
        }
        $sync_chars_regex = \Piwigo\Config\CurrentConfig::syncCharsRegex();
        foreach ($req_tokens as $token) {
            ($sync_chars_regex !== '' && (bool) preg_match($sync_chars_regex, $token)) or $this->ierror('Invalid chars in request', 400);
        }

        $this->derivativePath = $this->paths->root . CurrentConfig::derivativeDir() . $req;
        $this->derivativeUrlSuffix = $req;

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
            if (DerivativeUrlCodec::derivativeToUrl($type) === $deriv[0]) {
                $this->derivativeType = $type;
                $this->derivativeParams = $params;
                break;
            }
        }

        if ($this->derivativeType === null) {
            if (DerivativeUrlCodec::derivativeToUrl(ImageStdParams::CUSTOM) === $deriv[0]) {
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

        // Real bug, found via a fixture-regeneration discrepancy and
        // confirmed present identically in the 16.x-rewrite reference
        // (Image/DerivativePipeline.php): this used to unconditionally
        // prepend './' when the bare request path resolves to a real file,
        // "to match #images.path" (findByPath() below) -- but
        // Admin\Upload\UploadService::addUploadedFile() (both here and in
        // the reference) stores images.path WITHOUT a leading './', so that
        // match could never succeed for any freshly-uploaded image; visibly
        // confirmed as a broken next/previous-photo thumbnail on a real
        // picture page, not just a theoretical mismatch. The './' served no
        // filesystem purpose of its own ('./' is an inert path segment --
        // is_file()/filemtime()/PwgImage all resolve it identically with or
        // without it), and doesn't affect the str_contains() checks below
        // (pwg_representative/themes/plugins), which don't care about a
        // leading './' either -- so leaving $req bare here is a pure fix,
        // not a behavior trade-off. The '../' branch is unrelated and
        // unchanged: unlike './', a real '../' is NOT inert -- it's the
        // only thing that makes the subsequent is_file()/filemtime() calls
        // resolve to the correct (one-level-up) file at all.
        if (is_file($this->paths->root . $req . $ext)) {
            // $req already matches images.path as stored -- no rewrite needed.
        } elseif (is_file($this->paths->root . '../' . $req . $ext)) {
            $req = '../' . $req;
        }

        $this->srcLocation = $req . $ext;
        $this->srcPath = $this->paths->root . $this->srcLocation;
        $this->srcUrl = \Piwigo\Bootstrap\PresentationAccessor::urlService()
            ->getAbsoluteRootUrl(false) . '/' . $this->srcLocation;

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

        // height alone can still be null (see $originalSize's own docblock);
        // width is already guaranteed int by this point.
        [$width, $height] = $this->originalSize;
        if ($height === null) {
            return false;
        }
        $original_size = [$width, $height];

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
            if ($candidate->type === $params->type) {
                continue;
            }
            if ($candidate->use_watermark !== $use_watermark) {
                continue;
            }
            if ($candidate->max_width() < $params->max_width() || $candidate->max_height() < $params->max_height()) {
                continue;
            }
            $candidate_size = $candidate->compute_final_size($original_size);
            if ($dsize !== $params->compute_final_size($candidate_size)) {
                continue;
            }

            // Real bug, found live via a candidate-reuse test that never
            // observed any reuse regardless of fixture setup: SizingParams::
            // $max_crop is untyped (@var float per its own docblock, but
            // *every* real construction site -- classic()'s implicit
            // default, square()'s literal 1 -- assigns a plain *int*; even
            // DerivativeUrlCodec::charToFraction()'s int/int division
            // returns an int whenever it divides evenly, e.g. 'a' -> 0,
            // 'z' -> 1). A strict `=== 0.0`/`!== 0.0` float comparison
            // never matches a native int 0 in PHP (`0 === 0.0` is false),
            // so both branches below always took the "not zero" path for
            // every standard defined type -- $candidates stayed empty on
            // every real request, silently disabling this entire reuse
            // optimization (always falling back to regenerating from the
            // true original) rather than just never hitting the (harmless)
            // add_url_tokens() fast-path SizingParamsTest.php already
            // documents this exact int/float quirk for. compute() itself
            // (a few lines above, in the same class) already treats
            // max_crop as a plain number via `> 0`, not a float-typed
            // value -- matching that precedent, an explicit (float) cast
            // normalizes the comparison instead of switching to a loose
            // `==`/`!=` throughout this security-adjacent method.
            if ((float) $params->sizing->max_crop === 0.0) {
                if ((float) $candidate->sizing->max_crop !== 0.0) {
                    continue;
                }
            } else {
                if ($use_watermark && $candidate->use_watermark) {
                    continue;
                } // a square that requires watermark should not be generated from a larger derivative with watermark, because if the watermark is not centered on the large image, it will be cropped.
                if ((float) $candidate->sizing->max_crop !== 0.0) {
                    continue;
                } // this could be optimized
                $minSize = $params->sizing->min_size;
                if ($minSize === null) {
                    continue;
                }
                if ($candidate_size[0] < $minSize[0] || $candidate_size[1] < $minSize[1]) {
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
            // Part III: the "0 changes needed" redirect below (serve()'s own
            // "no change required" branch) must send the browser to this
            // *candidate derivative*, not the true original -- found live
            // while retiring this class's own remaining PHPWG_ROOT_PATH
            // reads: a raw filesystem-relative link straight into _data/i/
            // (the same link shape parseRequest()'s own $this->srcUrl fix
            // just closed for the true-original case) would 404, since
            // _data/i/ is deliberately unreachable now (SEC-33/35/38/47).
            // Same i.php?/{loc} URL shape DerivativeImage::build() already
            // uses, applying the identical type-token substitution to the
            // *request's own URL suffix* (captured in parseRequest(), before
            // $req lost its extension/type token) instead of to a
            // filesystem path -- still routes through this same
            // permission-checked controller, at a different derivative type.
            $rel_url = self::derivativeUrlPath($this->derivativeUrlSuffix, $params->type, $candidate->type);
            $this->srcUrl = \Piwigo\Bootstrap\PresentationAccessor::urlService()
                ->getAbsoluteRootUrl(false) . '/' . $rel_url;
            $this->rotationAngle = 0;
            return true;
        }
        return false;
    }

    /**
     * Part III: the mount-relative (no host/scheme, no app root prefix)
     * i.php URL for $urlSuffix (the raw request string, e.g.
     * 'upload/2026/08/01/foo-th.jpg') with its own derivative-type token
     * substituted from $fromType to $toType -- same i/[.php]/[?]/{loc}
     * shape Image\DerivativeImage::build() already uses for real (non-
     * redirect) derivative URL generation elsewhere in this codebase.
     * Static and side-effect-free (reads only CurrentConfig::phpExtensionInUrls()/
     * questionMarkInUrls()) so trySwitchSource()'s own type-substitution
     * logic -- the one piece of new, security-adjacent URL-construction
     * code this method introduces -- is directly unit-testable without the
     * rest of this class's DB/filesystem dependencies.
     */
    private static function derivativeUrlPath(string $urlSuffix, string $fromType, string $toType): string
    {
        $suffix = str_replace('-' . DerivativeUrlCodec::derivativeToUrl($fromType), '-' . DerivativeUrlCodec::derivativeToUrl($toType), $urlSuffix);
        $rel_url = 'i';
        if (\Piwigo\Config\CurrentConfig::phpExtensionInUrls()) {
            $rel_url .= '.php';
        }
        if (\Piwigo\Config\CurrentConfig::questionMarkInUrls()) {
            $rel_url .= '?';
        }

        return $rel_url . '/' . $suffix;
    }

    /**
     * Part III: the response body is read fully into a string rather than
     * streamed (former fpassthru()) -- same trade-off Controller\
     * ActionController's own docblock already established for original-file
     * serving: ResponseEmitter::emit() calls `echo $response->getBody()`,
     * which fully materializes a StreamInterface body into a string via
     * __toString() anyway, so a stream-backed body would buy nothing here;
     * genuine chunked streaming would need ResponseEmitter itself to
     * change, out of this phase's scope.
     */
    private function sendDerivative(false|int $expires): ResponseInterface
    {
        $derivative_path = $this->derivativePath;

        if (Request\ImageDerivativeRequest::fromGlobals()->isAjaxLoad) {
            $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();

            return ResponseFactory::json([
                'url' => $urlService->embellishUrl($urlService->getAbsoluteRootUrl() . $derivative_path),
            ]);
        }
        $fp = fopen($derivative_path, 'rb');
        if ($fp === false) {
            $this->ierror('Unable to open derivative file', 500);
        }

        $fstat = fstat($fp);
        if ($fstat === false) {
            fclose($fp);
            $this->ierror('Unable to stat derivative file', 500);
        }
        $body = stream_get_contents($fp);
        fclose($fp);
        if ($body === false) {
            $this->ierror('Unable to read derivative file', 500);
        }

        $headers = [
            'Last-Modified' => gmdate('D, d M Y H:i:s', $fstat['mtime']) . ' GMT',
            'Connection' => 'close',
        ];
        if ($expires !== false) {
            $headers['Expires'] = gmdate('D, d M Y H:i:s', $expires) . ' GMT';
        }

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
        $headers['Content-Type'] = $ctype;

        return ResponseFactory::raw($body, $headers);
    }
}
