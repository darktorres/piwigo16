<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Piwigo\Users\PermissionService;

/**
 * File download / inline-serve handler for original photos, representatives,
 * and format variants.
 * Corresponds to the former action.php entry-point.
 */
final class ActionController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(ACCESS_GUEST);

        $params = $request->getQueryParams();

        if (Config::isFormatsEnabled() && isset($params['format'])) {
            check_input_parameter('format', $_GET, false, PATTERN_ID);
            $get_format = input_int('format', null, $_GET);

            $query = 'SELECT * FROM ' . IMAGE_FORMAT_TABLE . ' WHERE format_id = ' . $get_format . ';';
            $formats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

            if (count($formats) == 0) {
                $this->error(400, 'Invalid request - format');
            }

            $format = $formats[0];
            $_GET['id'] = $format['image_id'];
            $_GET['part'] = 'f';
        }

        $get_id   = input_int('id', null, $_GET);
        $get_part = input_string('part', null, $_GET);
        if ($get_id === null || $get_part === null || !in_array($get_part, ['e', 'r', 'f'])) {
            $this->error(400, 'Invalid request - id/part');
        }

        $element_info = ServiceLocator::get(ImageRepository::class)->findById((int) $get_id);
        if (empty($element_info)) {
            $this->error(404, 'Requested id not found');
        }

        $is_admin_download = false;
        $get_pwg_token     = input_string('pwg_token', null, $_GET);
        if (PermissionService::get()->isAdmin() && $get_pwg_token !== null && get_pwg_token() == $get_pwg_token) {
            $is_admin_download = true;
            if (is_array($GLOBALS['user'] ?? null)) {
                $GLOBALS['user']['enabled_high'] = true;
            }
        }

        $src_image = new SrcImage($element_info);

        $query = '
SELECT id FROM ' . CATEGORIES_TABLE . '
    INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON category_id = id
  WHERE image_id = ' . $get_id . '
' . PermissionService::get()->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'image_id'], '    AND') . '
  LIMIT 1;';
        if (!$is_admin_download && ServiceLocator::get(Connection::class)->executeQuery($query)->fetchOne() === false) {
            $this->error(401, 'Access denied');
        }

        $file = '';
        switch ($get_part) {
            case 'e':
                $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
                if ($src_image->is_original() && !($user['enabled_high'] ?? false)) {
                    $deriv = new DerivativeImage(IMG_XXLARGE, $src_image);
                    if (!$deriv->same_as_source()) {
                        $this->error(401, 'Access denied e');
                    }
                }
                $file = get_element_path($element_info);
                break;
            case 'r':
                $file = original_to_representative(get_element_path($element_info), $element_info['representative_ext'] ?? '');
                break;
            case 'f':
                $file = original_to_format(get_element_path($element_info), $format['ext'] ?? '');
                $element_info['file'] = get_filename_wo_extension(is_scalar($element_info['file']) ? (string) $element_info['file'] : '') . '.' . (is_scalar($format['ext'] ?? null) ? (string) $format['ext'] : '');
                break;
        }

        if (empty($file)) {
            $this->error(404, 'Requested file not found');
        }

        if ($get_part == 'e') {
            pwg_log($get_id, 'high');
        } elseif ($get_part == 'f') {
            pwg_log($get_id, 'high', is_scalar($format['format_id'] ?? null) ? (string) $format['format_id'] : null);
        }

        trigger_notify('loc_action_before_http_headers');

        $http_headers = [];
        $ctype        = null;

        if (!url_is_remote($file)) {
            if (!is_readable($file)) {
                $this->error(404, "Requested file not found - $file");
            }
            $http_headers[] = 'Content-Length: ' . filesize($file);
            if (function_exists('mime_content_type')) {
                $ctype = mime_content_type($file);
            }
            $gmt_mtime      = gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT';
            $http_headers[] = 'Last-Modified: ' . $gmt_mtime;

            if ($get_part !== 'f' && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                set_status_header(304);
                foreach ($http_headers as $header) {
                    header($header);
                }
                exit();
            }
        }

        if (!isset($ctype)) {
            $ctype = $this->guessMimeType(get_extension($file));
        }

        $http_headers[] = 'Content-Type: ' . $ctype;
        $http_headers[] = 'Cache-Control: public';

        $elementFile = is_scalar($element_info['file'] ?? null) ? (string) $element_info['file'] : basename($file);
        if (input_string('download', null, $_GET) !== null) {
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
        set_status_header($code);
        echo $msg;
        exit();
    }
}
