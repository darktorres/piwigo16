<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Paths;
use Piwigo\Url\UrlService;

final readonly class DerivativePipeline
{
    public function __construct(private Paths $paths)
    {
    }

    public function ierror(string $msg, int $code): never
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
            /** @var mixed $protocolRaw */
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

    public function timeStep(float &$step): int
    {
        $tmp  = $step;
        $step = microtime(true);
        return intval(1000.0 * ($step - $tmp));
    }

    /** @param string[] $tokens */
    public function parseCustomParams(array $tokens): DerivativeParams
    {
        if (count($tokens) < 1) {
            $this->ierror('Empty array while parsing Sizing', 400);
        }

        $crop     = 0;
        $min_size = null;
        $token    = array_shift($tokens);

        if ($token[0] == 's') {
            $size = DerivativeEncoding::urlToSize(substr($token, 1));
        } elseif ($token[0] == 'e') {
            $crop     = 1;
            $size     = $min_size = DerivativeEncoding::urlToSize(substr($token, 1));
        } else {
            $size = DerivativeEncoding::urlToSize($token);
            if (count($tokens) < 2) {
                $this->ierror('Sizing arr', 400);
            }
            $token    = array_shift($tokens);
            $crop     = DerivativeEncoding::charToFraction($token);
            $token    = array_shift($tokens);
            $min_size = DerivativeEncoding::urlToSize($token ?? '');
        }
        return new DerivativeParams(new SizingParams($size, $crop, $min_size ?? [0, 0]));
    }

    public function parseRequest(ImageDerivativeContext $ctx): DerivativeParams
    {
        /** @var mixed $reqRaw */
        $reqRaw = $_SERVER['QUERY_STRING'] ?? '';
        $req    = is_string($reqRaw) ? $reqRaw : '';
        if (($pos = strpos($req, '&')) !== false) {
            $req = substr($req, 0, $pos);
        }
        $req           = rawurldecode($req);
        $ctx->rootPath = '';

        $req = ltrim($req, '/');
        if (str_starts_with($req, 'i/')) {
            $req = substr($req, 2);
        }

        foreach (preg_split('#/+#', $req) ?: [] as $token) {
            preg_match(Config::syncCharsRegex(), $token) or $this->ierror('Invalid chars in request', 400);
        }

        $ctx->derivativePath = $this->paths->root . Config::derivativeDir() . $req;

        $pos = strrpos($req, '.');
        $pos !== false || $this->ierror('Missing .', 400);
        $ext                = substr($req, $pos);
        $ctx->derivativeExt = $ext;
        $req                = substr($req, 0, $pos);

        $pos = strrpos($req, '-');
        $pos !== false || $this->ierror('Missing -', 400);
        $deriv = substr($req, $pos + 1);
        $req   = substr($req, 0, $pos);
        $deriv = explode('_', $deriv);

        foreach (ImageStdParams::getDefinedTypeMap() as $type => $params) {
            if (DerivativeEncoding::derivativeToUrl($type) == $deriv[0]) {
                $ctx->derivativeType   = $type;
                $ctx->derivativeParams = $params;
                break;
            }
        }

        if ($ctx->derivativeType === null) {
            if (DerivativeEncoding::derivativeToUrl(DerivativeSize::Custom->value) == $deriv[0]) {
                $ctx->derivativeType = DerivativeSize::Custom->value;
            } else {
                $this->ierror('Unknown parsing type', 400);
            }
        }
        array_shift($deriv);

        if ($ctx->derivativeType == DerivativeSize::Custom->value) {
            $params = $ctx->derivativeParams = $this->parseCustomParams($deriv);
            ImageStdParams::applyGlobal($params);

            if ($params->sizing->ideal_size[0] < 20 || $params->sizing->ideal_size[1] < 20) {
                $this->ierror('Invalid size', 400);
            }
            if ($params->sizing->max_crop < 0 || $params->sizing->max_crop > 1) {
                $this->ierror('Invalid crop', 400);
            }
            $keyTokens = [];
            $params->addUrlTokens($keyTokens);
            $key = implode('_', $keyTokens);
            if (!isset(ImageStdParams::$custom[$key])) {
                $this->ierror('Size not allowed', 403);
            }
        }

        if (is_file($this->paths->root . $req . $ext)) {
            $req = './' . $req;
        } elseif (is_file($this->paths->root . '../' . $req . $ext)) {
            $req = '../' . $req;
        }

        $ctx->srcLocation = $req . $ext;
        $ctx->srcPath     = $this->paths->root . $ctx->srcLocation;
        $ctx->srcUrl      = $ctx->rootPath . $ctx->srcLocation;

        $dp = $ctx->derivativeParams;
        if (!($dp instanceof DerivativeParams)) {
            $this->ierror('Invalid derivative params', 400);
        }
        return $dp;
    }

    public function trySwitchSource(DerivativeParams $params, int $original_mtime, ImageDerivativeContext $ctx): bool
    {
        if ($ctx->originalSize === null) {
            return false;
        }
        $original_size = $ctx->originalSize;
        if ($ctx->rotationAngle == 90 || $ctx->rotationAngle == 270) {
            [$original_size[0], $original_size[1]] = [$original_size[1], $original_size[0]];
        }
        $dsize         = $params->computeFinalSize($original_size);
        $use_watermark = $params->use_watermark;
        if ($use_watermark) {
            $use_watermark = $params->willWatermark($dsize);
        }
        $candidates = [];
        foreach (ImageStdParams::getDefinedTypeMap() as $candidate) {
            if ($candidate->type == $params->type) {
                continue;
            }
            if ($candidate->use_watermark != $use_watermark) {
                continue;
            }
            if ($candidate->maxWidth() < $params->maxWidth() || $candidate->maxHeight() < $params->maxHeight()) {
                continue;
            }
            $candidate_size = $candidate->computeFinalSize($original_size);
            if ($dsize != $params->computeFinalSize($candidate_size)) {
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
                $paramsMinSize = $params->sizing->min_size;
                if ($candidate_size[0] < ($paramsMinSize[0] ?? 0) || $candidate_size[1] < ($paramsMinSize[1] ?? 0)) {
                    continue;
                }
            }
            $candidates[] = $candidate;
        }
        foreach (array_reverse($candidates) as $candidate) {
            $candidate_path  = str_replace(
                '-' . DerivativeEncoding::derivativeToUrl($params->type),
                '-' . DerivativeEncoding::derivativeToUrl($candidate->type),
                $ctx->derivativePath
            );
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
            $ctx->srcUrl           = $ctx->rootPath . substr($candidate_path, strlen($this->paths->root));
            $ctx->rotationAngle    = 0;
            return true;
        }
        return false;
    }

    public function sendDerivative(int|false $expires, ImageDerivativeContext $ctx): void
    {
        if (isset($_GET['ajaxload']) && $_GET['ajaxload'] == 'true') {
            $relativeUrl = substr($ctx->derivativePath, strlen($this->paths->root));
            echo json_encode(['url' => UrlService::embellishUrl(UrlService::getAbsoluteRootUrl() . $relativeUrl)]);
            return;
        }
        $fp = fopen($ctx->derivativePath, 'rb');
        if ($fp === false) {
            $this->ierror('Cannot open derivative file', 500);
        }
        $fstat = fstat($fp);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fstat !== false ? $fstat['mtime'] : 0) . ' GMT');
        if ($expires !== false) {
            header('Expires: ' . gmdate('D, d M Y H:i:s', $expires) . ' GMT');
        }
        header('Connection: close');
        $ctype = 'application/octet-stream';
        switch (strtolower($ctx->derivativeExt)) {
            case '.jpe': case '.jpeg': case '.jpg': $ctype = 'image/jpeg';
                break;
            case '.png':  $ctype = 'image/png';
                break;
            case '.gif':  $ctype = 'image/gif';
                break;
            case '.webp': $ctype = 'image/webp';
                break;
        }
        header('Content-Type: ' . $ctype);
        fpassthru($fp);
        fclose($fp);
    }
}
