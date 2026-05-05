<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;

final class AdminService
{
    public function __construct(
        private readonly Connection $conn,
    ) {
    }

    /** @return string[] */
    public function pwgURL(): array
    {
        return [
            'HOME'       => PHPWG_URL,
            'WIKI'       => PHPWG_URL . '/doc',
            'DEMO'       => PHPWG_URL . '/demo',
            'FORUM'      => PHPWG_URL . '/forum',
            'BUGS'       => PHPWG_URL . '/bugs',
            'EXTENSIONS' => PHPWG_URL . '/ext',
        ];
    }

    public function createTableAddCharacterSet(mixed $query): string
    {
        $query = is_scalar($query) ? (string) $query : '';
        if (version_compare(\Piwigo\Db\DbInfo::version(), '4.1.0', '<')) {
            return $query;
        }
        $query = trim($query, ';');
        if (preg_match('/^CREATE\s+TABLE/i', $query)) {
            $query .= ' DEFAULT CHARACTER SET utf8';
        }
        return $query . ';';
    }

    /** @return string[] */
    public function getExtents(string $start = ''): array
    {
        if ($start === '') {
            $start = './template-extension';
        }
        $dir    = opendir($start);
        $extents = [];
        if ($dir === false) {
            return $extents;
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..' || $file === '.svn') {
                continue;
            }
            $path = $start . '/' . $file;
            if (is_dir($path)) {
                $extents = array_merge($extents, $this->getExtents($path));
            } elseif (!is_link($path) && file_exists($path) && get_extension($path) === 'tpl') {
                $extents[] = substr($path, 21);
            }
        }
        closedir($dir);
        return $extents;
    }

    /** @return string[] */
    public function getDirs(string $directory): array
    {
        $subDirs = [];
        $opendir = opendir($directory);
        if ($opendir !== false) {
            while ($file = readdir($opendir)) {
                if ($file !== '.' && $file !== '..' && is_dir($directory . '/' . $file) && $file !== '.svn') {
                    $subDirs[] = $file;
                }
            }
            closedir($opendir);
        }
        return $subDirs;
    }

    public function deltree(string $path, ?string $trashPath = null): bool
    {
        if (is_dir($path)) {
            $fh = opendir($path);
            if ($fh !== false) {
                while ($file = readdir($fh)) {
                    if ($file !== '.' && $file !== '..') {
                        $pathfile = $path . '/' . $file;
                        if (is_dir($pathfile)) {
                            $this->deltree($pathfile, $trashPath);
                        } else {
                            \Piwigo\Core\Filesystem::tryUnlink($pathfile);
                        }
                    }
                }
                closedir($fh);
            }
            if (\Piwigo\Core\Filesystem::tryRmdir($path)) {
                return true;
            }
            if (!empty($trashPath)) {
                if (!is_dir($trashPath)) {
                    mkgetdir($trashPath, MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_HTACCESS);
                }
                while ($r = $trashPath . '/' . md5(uniqid((string) random_int(0, mt_getrandmax()), true))) {
                    if (!is_dir($r)) {
                        \Piwigo\Core\Filesystem::tryRename($path, $r);
                        break;
                    }
                }
            } else {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * @param string[] $requested
     * @return array<mixed>
     */
    public function getAdminClientCacheKeys(array $requested = []): array
    {
        $tables = [
            'categories' => CATEGORIES_TABLE,
            'groups'     => GROUPS_TABLE,
            'images'     => IMAGES_TABLE,
            'tags'       => TAGS_TABLE,
            'users'      => USER_INFOS_TABLE,
        ];
        if (empty($requested)) {
            $requested = array_keys($tables);
        } else {
            $requested = array_intersect($requested, array_keys($tables));
        }
        $keys = ['_hash' => md5((string) get_absolute_root_url())];
        foreach ($requested as $item) {
            $keys[$item] = $this->conn->executeQuery(
                'SELECT CONCAT(UNIX_TIMESTAMP(MAX(lastmodified)), "_", COUNT(*)) FROM `' . $tables[$item] . '`'
            )->fetchOne();
        }
        return $keys;
    }

    public function numberFormatHumanReadable(mixed $numbers): string
    {
        $readable = ['', 'k', 'M'];
        $index    = 0;
        $numbers  = empty($numbers) ? 0 : (is_numeric($numbers) ? (float) $numbers : 0);
        while ($numbers >= 1000) {
            $numbers /= 1000;
            $index++;
            if ($index > count($readable) - 1) {
                $index--;
                break;
            }
        }
        $decimals = $readable[$index] === '' ? 0 : 1;
        return number_format($numbers, $decimals) . $readable[$index];
    }

    public function getNewsletterSubscribeBaseUrl(string $language = 'en_UK'): string
    {
        return PHPWG_URL . '/announcement/subscribe/';
    }

    public function getOldNewslettersBaseUrl(string $language = 'en_UK'): string
    {
        return PHPWG_URL . '/newsletter';
    }

    public function getActiveMenu(mixed $menuPage): int
    {
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        if (isset($page['active_menu']) && is_int($page['active_menu'])) {
            return $page['active_menu'];
        }
        $mp = is_scalar($menuPage) ? (string) $menuPage : '';
        return match ($mp) {
            'photo', 'photos_add', 'rating', 'tags', 'batch_manager' => 0,
            'album', 'cat_list', 'albums', 'cat_options', 'cat_search', 'permalinks' => 1,
            'user_list', 'user_perm', 'group_list', 'group_perm', 'notification_by_mail', 'user_activity' => 2,
            'site_manager', 'site_update', 'stats', 'history', 'maintenance', 'comments', 'updates' => 3,
            'configuration', 'derivatives', 'extend_for_templates', 'menubar', 'themes', 'theme', 'languages' => 4,
            default => -1,
        };
    }

    /**
     * @param int[] $elementIds
     * @param string[] $name
     * @return int[]
     */
    public function orderByName(array $elementIds, array $name): array
    {
        $ordered = [];
        foreach ($elementIds as $kId => $elementId) {
            $key           = strtolower($name[$elementId]) . '-' . $name[$elementId] . '-' . $kId;
            $ordered[$key] = $elementId;
        }
        ksort($ordered);
        return $ordered;
    }

    /**
     * @param array<mixed> $getData
     * @param array<mixed> $postData
     * @param-out string $dest
     */
    public function fetchRemote(string $src, mixed &$dest, array $getData = [], array $postData = [], string $userAgent = 'Piwigo', int $step = 0): bool
    {
        if (!url_is_remote($src)) {
            $content = is_readable($src) ? file_get_contents($src) : false;
            if ($content !== false) {
                if (is_resource($dest)) {
                    fwrite($dest, $content);
                    $dest = '';
                } else {
                    $dest = $content;
                }
                return true;
            }
            return false;
        }
        if ($step > 3) {
            return false;
        }
        $method  = empty($postData) ? 'GET' : 'POST';
        $request = empty($postData) ? '' : http_build_query($postData, '', '&');
        if (!empty($getData)) {
            $src .= !str_contains($src, '?') ? '?' : '&';
            $src .= http_build_query($getData, '', '&');
        }
        if (!is_resource($dest)) {
            $dest = '';
        }
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            set_error_handler(static fn (): bool => true);
            try {
                $ch = curl_init();
                if ($ch !== false) {
                    if (Config::has('use_proxy') && Config::useProxy()) {
                        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, false);
                        curl_setopt($ch, CURLOPT_PROXY, Config::proxyServer());
                        $proxyAuth = Config::proxyAuth();
                        if (Config::has('proxy_auth') && !empty($proxyAuth)) {
                            curl_setopt($ch, CURLOPT_PROXYUSERPWD, (string) $proxyAuth);
                        }
                    }
                    if (!empty($src)) {
                        curl_setopt($ch, CURLOPT_URL, $src);
                    }
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    if (!empty($userAgent)) {
                        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
                    }
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    if ($method === 'POST') {
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                    }
                    $content      = curl_exec($ch);
                    $headerLength = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                    $status       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                } else {
                    $content = false;
                    $headerLength = 0;
                    $status       = 0;
                }
                unset($ch);
            } finally {
                restore_error_handler();
            }
            if (is_string($content) && $status >= 200 && $status < 400) {
                if (preg_match('/Location:\s+?(.+)/', substr($content, 0, $headerLength), $m)) {
                    return $this->fetchRemote($m[1], $dest, [], [], $userAgent, $step + 1);
                }
                $content = substr($content, $headerLength);
                if (is_resource($dest)) {
                    fwrite($dest, $content);
                    $dest = '';
                } else {
                    $dest = $content;
                }
                return true;
            }
        }
        if (ini_get('allow_url_fopen')) {
            $opts = ['http' => ['method' => $method, 'user_agent' => $userAgent]];
            if ($method === 'POST') {
                $opts['http']['content'] = $request;
            }
            set_error_handler(static fn (): bool => true);
            try {
                $context = stream_context_create($opts);
                $content = file_get_contents($src, false, $context);
            } finally {
                restore_error_handler();
            }
            if ($content !== false) {
                if (is_resource($dest)) {
                    fwrite($dest, $content);
                    $dest = '';
                } else {
                    $dest = $content;
                }
                return true;
            }
        }
        $srcParsed = parse_url($src);
        if ($srcParsed === false || !isset($srcParsed['host'])) {
            return false;
        }
        $host = $srcParsed['host'];
        $path = $srcParsed['path'] ?? '/';
        $path .= isset($srcParsed['query']) ? '?' . $srcParsed['query'] : '';
        set_error_handler(static fn (): bool => true);
        try {
            $s = fsockopen($host, 80, $errno, $errstr, 5);
        } finally {
            restore_error_handler();
        }
        if ($s === false) {
            return false;
        }
        $httpRequest  = $method . ' ' . $path . " HTTP/1.0\r\n";
        $httpRequest .= 'Host: ' . $host . "\r\n";
        if ($method === 'POST') {
            $httpRequest .= "Content-Type: application/x-www-form-urlencoded;\r\n";
            $httpRequest .= 'Content-Length: ' . strlen($request) . "\r\n";
        }
        $httpRequest .= 'User-Agent: ' . $userAgent . "\r\n";
        $httpRequest .= "Accept: */*\r\n\r\n";
        $httpRequest .= $request;
        fwrite($s, $httpRequest);
        $i         = 0;
        $inContent = false;
        while (!feof($s)) {
            $line = fgets($s);
            if ($line === false) {
                break;
            }
            if (rtrim($line, "\r\n") === '' && !$inContent) {
                $inContent = true;
                $i++;
                continue;
            }
            if ($i === 0) {
                if (!preg_match('/HTTP\/(\d\.\d)\s*(\d+)\s*(.*)/', rtrim($line, "\r\n"), $m)) {
                    fclose($s);
                    return false;
                }
                $status = (int) $m[2];
                if ($status < 200 || $status >= 400) {
                    fclose($s);
                    return false;
                }
            }
            if (!$inContent) {
                if (preg_match('/Location:\s+?(.+)$/', rtrim($line, "\r\n"), $m)) {
                    fclose($s);
                    return $this->fetchRemote(trim($m[1]), $dest, [], [], $userAgent, $step + 1);
                }
                $i++;
                continue;
            }
            if (is_resource($dest)) {
                fwrite($dest, $line);
            } else {
                $dest .= $line;
            }
            $i++;
        }
        fclose($s);
        if (!is_string($dest)) {
            $dest = '';
        }
        return true;
    }

    /** @return array<mixed> */
    public function getPiwigoNews(): array
    {
        $langInfo = is_array($GLOBALS['lang_info'] ?? null) ? $GLOBALS['lang_info'] : [];
        $langCode = is_scalar($langInfo['code'] ?? null) ? (string) $langInfo['code'] : '';
        $news     = null;
        $cachePath = PHPWG_ROOT_PATH . Config::dataLocation() . 'cache/piwigo_latest_news-' . $langCode . '.cache.php';
        if (!is_file($cachePath) || filemtime($cachePath) < strtotime('24 hours ago')) {
            $url     = PHPWG_URL . '/ws.php?method=porg.news.getLatest&format=json';
            $content = '';
            if ($this->fetchRemote($url, $content)) {
                $porgNews = json_decode($content, true);
                if (is_array($porgNews) && isset($porgNews['result']) && is_array($porgNews['result'])) {
                    $topic    = $porgNews['result'];
                    $postedOn = is_scalar($topic['posted_on'] ?? null) ? (string) $topic['posted_on'] : null;
                    $news     = [
                        'id'        => $topic['topic_id'] ?? null,
                        'subject'   => $topic['subject'] ?? null,
                        'posted_on' => $postedOn,
                        'posted'    => format_date($postedOn),
                        'url'       => $topic['url'] ?? null,
                    ];
                }
                if (mkgetdir(dirname($cachePath))) {
                    file_put_contents($cachePath, serialize($news));
                }
            } else {
                return [];
            }
        }
        if ($news === null) {
            $cacheContents = file_get_contents($cachePath);
            $unserialized  = $cacheContents !== false ? unserialize($cacheContents) : [];
            $news          = is_array($unserialized) ? $unserialized : [];
        }
        return $news;
    }

    public function getGraphicsLibrary(): string
    {
        $library = PwgImage::get_library();
        if ($library === false) {
            return '';
        }
        switch (PwgImage::get_library()) {
            case 'ext_imagick':
                exec(Config::extImagickDir() . PwgImage::get_ext_imagick_command() . ' -version', $returnarray);
                if (isset($returnarray[0]) && preg_match('/Version: ImageMagick (\d+\.\d+\.\d+-?\d*)/', $returnarray[0], $match)) {
                    $library .= '/' . $match[1];
                }
                break;
            case 'imagick':
                $img     = new \Imagick();
                $version = $img->getVersion();
                if (preg_match('/ImageMagick \d+\.\d+\.\d+-?\d*/', $version['versionString'], $match)) {
                    $library .= '/' . $match[0];
                }
                break;
            case 'gd':
                $gdInfo   = gd_info();
                $library .= '/' . ($gdInfo['GD Version'] ?? '');
                break;
        }
        return $library;
    }

    public function getGraphicsLibraryLabel(): string
    {
        $parts          = explode('/', $this->getGraphicsLibrary());
        $libraryCode    = $parts[0];
        $libraryVersion = $parts[1] ?? '';
        $labelForLib  = ['imagick' => 'ImageMagick', 'ext_imagick' => 'External ImageMagick', 'gd' => 'GD'];
        return ($labelForLib[$libraryCode] ?? $libraryCode) . ' ' . $libraryVersion;
    }

    /** @return array<string,mixed> */
    public function getPwgGeneralStatitics(): array
    {
        $stats    = [];
        $imgRepo  = ServiceLocator::get(\Piwigo\Image\ImageRepository::class);
        $catRepo  = ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);
        $tagRepo  = ServiceLocator::get(\Piwigo\Tag\TagRepository::class);
        $userRepo = ServiceLocator::get(\Piwigo\Users\UserRepository::class);
        $histRepo = ServiceLocator::get(\Piwigo\History\HistoryRepository::class);

        $stats['nb_photos']     = $imgRepo->countAll();
        $stats['nb_categories'] = $catRepo->countAll();
        $stats['nb_tags']       = $tagRepo->countAll();
        $stats['nb_image_tag']  = $tagRepo->countImageTags();
        $stats['nb_users']      = $userRepo->countAll(USERS_TABLE);
        $stats['nb_admins']     = $userRepo->countByStatus(['webmaster', 'admin']);
        $stats['nb_groups']     = $userRepo->countGroups();
        $stats['nb_rates']      = $imgRepo->countRatings();
        $stats['nb_views']      = $histRepo->sumPageViews();
        $stats['disk_usage']    = $imgRepo->sumFilesizeKb();
        $stats['nb_formats']          = $imgRepo->countFormats();
        $stats['formats_disk_usage']  = $imgRepo->sumFormatFilesizeKb();
        $stats['disk_usage']         += $stats['formats_disk_usage'];
        return $stats;
    }

    public function getInstallationDate(): ?string
    {
        $piwigoOrigins = '2001-09-01 00:00:00';
        $candidate     = null;
        $users = get_dbal_connection()->executeQuery(
            'SELECT registration_date FROM ' . USER_INFOS_TABLE . ' WHERE user_id = 2'
        )->fetchAllAssociative();
        if (count($users) > 0) {
            $candidate = $users[0]['registration_date'];
        }
        if (empty($candidate) || strtotime(is_scalar($candidate) ? (string) $candidate : '') < strtotime($piwigoOrigins)) {
            $users = get_dbal_connection()->executeQuery(
                'SELECT MIN(registration_date) AS min_registration_date FROM ' . USER_INFOS_TABLE . " WHERE registration_date > '$piwigoOrigins'"
            )->fetchAllAssociative();
            if (count($users) > 0) {
                $candidate = $users[0]['min_registration_date'];
            }
        }
        if (empty($candidate) || strtotime(is_scalar($candidate) ? (string) $candidate : '') < strtotime($piwigoOrigins)) {
            $images = get_dbal_connection()->executeQuery(
                'SELECT date_available FROM ' . IMAGES_TABLE . ' ORDER BY id ASC LIMIT 1'
            )->fetchAllAssociative();
            if (count($images) > 0) {
                $candidate = $images[0]['date_available'];
            }
        }
        return ($candidate && is_scalar($candidate)) ? (string) $candidate : null;
    }
}
