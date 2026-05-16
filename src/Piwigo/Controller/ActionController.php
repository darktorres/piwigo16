<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Event\Lifecycle\LocActionBeforeHttpHeaders;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * File download / inline-serve handler for original photos, representatives,
 * and format variants.
 * Corresponds to the former action.php entry-point.
 */
final readonly class ActionController implements ControllerInterface
{
    public function __construct(
        private Connection $conn,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);

        $params = $request->getQueryParams();
        $format = [];

        if (Config::isFormatsEnabled() && isset($params['format'])) {
            $this->inputValidator->check('format', $_GET, false, ValidationPattern::ID);
            $get_format = StringUtil::inputInt('format', null, $_GET);

            $query = 'SELECT * FROM ' . Tables::imageFormat() . ' WHERE format_id = ' . ($get_format ?? 0) . ';';
            $formats = $this->conn->executeQuery($query)->fetchAllAssociative();

            if (count($formats) == 0) {
                $this->error(400, 'Invalid request - format');
            }

            $format = $formats[0];
            $_GET['id'] = $format['image_id'];
            $_GET['part'] = 'f';
        }

        $get_id   = StringUtil::inputInt('id', null, $_GET);
        $get_part = StringUtil::inputString('part', null, $_GET);
        if ($get_id === null || $get_part === null || !in_array($get_part, ['e', 'r', 'f'])) {
            $this->error(400, 'Invalid request - id/part');
        }

        $element_info = $this->imageRepository->findById($get_id);
        if ($element_info === null || count($element_info) === 0) {
            $this->error(404, 'Requested id not found');
        }

        $is_admin_download = false;
        $get_pwg_token     = StringUtil::inputString('pwg_token', null, $_GET);
        if ($this->permissionService->isAdmin() && $get_pwg_token !== null && $this->csrfService->getToken() == $get_pwg_token) {
            $is_admin_download = true;
            if (CurrentUser::isInitialized()) {
                CurrentUser::get()->enabledHigh = true;
                CurrentUser::get()->rawAttributes['enabled_high'] = true;
            }
        }

        $src_image = new SrcImage($element_info);

        $query = '
SELECT id FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON category_id = id
  WHERE image_id = ' . $get_id . '
' . $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'image_id'], '    AND') . '
  LIMIT 1;';
        if (!$is_admin_download && $this->conn->executeQuery($query)->fetchOne() === false) {
            $this->error(401, 'Access denied');
        }

        $file = '';
        switch ($get_part) {
            case 'e':
                $user = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
                if ($src_image->isOriginal() && ($user['enabled_high'] ?? false) === false) {
                    $deriv = new DerivativeImage(DerivativeSize::TwoXLarge->value, $src_image);
                    if (!$deriv->sameAsSource()) {
                        $this->error(401, 'Access denied e');
                    }
                }
                $file = StringUtil::getElementPath($element_info);
                break;
            case 'r':
                $reprExt = $element_info['representative_ext'] ?? null;
                $file = StringUtil::originalToRepresentative(StringUtil::getElementPath($element_info), is_string($reprExt) ? $reprExt : '');
                break;
            case 'f':
                $formatExt = $format['ext'] ?? null;
                $file = StringUtil::originalToFormat(StringUtil::getElementPath($element_info), is_string($formatExt) ? $formatExt : '');
                $element_info['file'] = StringUtil::getFilenameWoExtension(is_string($element_info['file'] ?? null) ? $element_info['file'] : '') . '.' . (is_string($format['ext'] ?? null) ? $format['ext'] : '');
                break;
        }

        if (empty($file)) {
            $this->error(404, 'Requested file not found');
        }

        if ($get_part == 'e') {
            $this->activityLogger->pageView($get_id, 'high');
        } elseif ($get_part == 'f') {
            $this->activityLogger->pageView($get_id, 'high', is_scalar($format['format_id'] ?? null) ? (string) $format['format_id'] : null);
        }

        $this->dispatcher->dispatch(new LocActionBeforeHttpHeaders());

        $http_headers = [];
        $ctype        = null;

        if (!UrlService::urlIsRemote($file)) {
            if (!is_readable($file)) {
                $this->error(404, "Requested file not found - $file");
            }
            $http_headers[] = 'Content-Length: ' . (int) filesize($file);
            if (function_exists('mime_content_type')) {
                $mimeResult = mime_content_type($file);
                $ctype = $mimeResult !== false ? $mimeResult : null;
            }
            $gmt_mtime      = gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT';
            $http_headers[] = 'Last-Modified: ' . $gmt_mtime;

            if ($get_part !== 'f' && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                $this->htmlService->setStatusHeader(304);
                foreach ($http_headers as $header) {
                    header($header);
                }
                exit();
            }
        }

        if (!isset($ctype)) {
            $ctype = $this->guessMimeType(StringUtil::getExtension($file));
        }

        $http_headers[] = 'Content-Type: ' . $ctype;
        $http_headers[] = 'Cache-Control: public';

        $elementFile = is_scalar($element_info['file'] ?? null) ? (string) $element_info['file'] : basename($file);
        if (StringUtil::inputString('download', null, $_GET) !== null) {
            $http_headers[] = 'Content-Disposition: attachment; filename="' . htmlspecialchars_decode($elementFile) . '";';
            $http_headers[] = 'Content-Transfer-Encoding: binary';
        } else {
            $http_headers[] = 'Content-Disposition: inline; filename="' . basename($file) . '";';
        }

        foreach ($http_headers as $header) {
            header($header);
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        if (ob_get_length() !== false) {
            ob_flush();
        }
        flush();
        readfile($file);

        return ResponseFactory::create(200);
    }

    private function guessMimeType(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpe', 'jpeg', 'jpg' => 'image/jpeg',
            'png'                => 'image/png',
            'gif'                => 'image/gif',
            'webp'               => 'image/webp',
            'tiff', 'tif'        => 'image/tiff',
            'txt'                => 'text/plain',
            'html', 'htm'        => 'text/html',
            'xml'                => 'text/xml',
            'pdf'                => 'application/pdf',
            'zip'                => 'application/zip',
            'ogg'                => 'application/ogg',
            default              => 'application/octet-stream',
        };
    }

    private function error(int $code, string $msg): never
    {
        $this->htmlService->setStatusHeader($code);
        echo $msg;
        exit();
    }
}
