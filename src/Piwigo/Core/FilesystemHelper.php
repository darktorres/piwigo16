<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: relocated from include/functions.inc.php, unchanged logic.
 *
 * P23 batch 8f-4: the MKGETDIR_ bitmask flags moved here as class
 * constants (include/functions.inc.php is deleted). Beyond the mechanical
 * relocation this fixes a real, documented live fatal: mkgetdir()'s
 * default parameter used to reference the global MKGETDIR_DEFAULT, which
 * only existed once include/functions.inc.php had run -- i.php's
 * deliberately-lighter "fast bootstrap" path never loaded that file, so a
 * cold-cache thumbnail generation calling mkgetdir() with default args
 * fataled (surfaced live via admin.php?page=comments, noted in the 8f-3
 * batch log). Class constants are autoloaded with the class itself, so
 * the default is now self-contained on every path.
 */
final class FilesystemHelper
{
    /**
     * no option for mkgetdir()
     */
    public const int MKGETDIR_NONE = 0;

    /**
     * sets mkgetdir() recursive
     */
    public const int MKGETDIR_RECURSIVE = 1;

    /**
     * sets mkgetdir() exit script on error
     */
    public const int MKGETDIR_DIE_ON_ERROR = 2;

    /**
     * sets mkgetdir() add a index.htm file
     */
    public const int MKGETDIR_PROTECT_INDEX = 4;

    /**
     * sets mkgetdir() add a .htaccess file
     */
    public const int MKGETDIR_PROTECT_HTACCESS = 8;

    /**
     * default options for mkgetdir() = MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX
     */
    public const int MKGETDIR_DEFAULT = self::MKGETDIR_RECURSIVE | self::MKGETDIR_DIE_ON_ERROR | self::MKGETDIR_PROTECT_INDEX;

    private static ?HtmlRenderingInterface $htmlRenderer = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same static-setter shape as Piwigo\Core\Lang::setDefaultLanguageProvider(),
     * needed because this L1Infrastructure class may not depend on
     * L3Presentation's HtmlService directly (deptrac).
     */
    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    private static function fatalError(string $msg): never
    {
        if (self::$htmlRenderer instanceof \Piwigo\Core\HtmlRenderingInterface) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * creates directory if not exists and ensures that directory is writable
     *
     * @param int $flags combination of self::MKGETDIR_xxx
     */
    public static function mkgetdir(string $dir, int $flags = self::MKGETDIR_DEFAULT): bool
    {
        if (! is_dir($dir)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            $chmod_value = \Piwigo\Config\Config::chmodValue();
            $mkd = @mkdir($dir, $chmod_value, ((bool) ($flags & self::MKGETDIR_RECURSIVE)) ? true : false);
            umask($umask);
            // Retest existence on mkdir() failure: concurrent requests (e.g.
            // parallel i.php derivative generations on a cold cache) race to
            // create the same directory, and the losers fail with EEXIST on
            // slow filesystems -- that is success, not an error (re-check
            // ported from HEAD i.php's local mkgetdir()).
            if ($mkd === false && ! is_dir($dir)) {
                ! (bool) ($flags & self::MKGETDIR_DIE_ON_ERROR) or self::fatalError("{$dir} " . Lang::t('no write access'));
                return false;
            }
            if ((bool) ($flags & self::MKGETDIR_PROTECT_HTACCESS)) {
                $file = $dir . '/.htaccess';
                file_exists($file) or (bool) @file_put_contents($file, 'deny from all');
            }
            if ((bool) ($flags & self::MKGETDIR_PROTECT_INDEX)) {
                $file = $dir . '/index.htm';
                file_exists($file) or (bool) @file_put_contents($file, 'Not allowed!');
            }
        }
        if (! is_writable($dir)) {
            ! (bool) ($flags & self::MKGETDIR_DIE_ON_ERROR) or self::fatalError("{$dir} " . Lang::t('no write access'));
            return false;
        }
        return true;
    }

    /**
     * makes sure a index.htm protects the directory from browser file listing
     */
    public static function secureDirectory(string $dir): void
    {
        $file = $dir . '/index.htm';
        if (! file_exists($file)) {
            @file_put_contents($file, 'Not allowed!');
        }
    }

    /**
     * P23 batch 8d: ported from admin/include/functions.php's
     * get_fs_directories(). Lives here (not Piwigo\Admin) because a real
     * caller, Piwigo\Site\LocalSiteReader (L2bExtendedDomain), can't
     * depend on L4Integration -- this class is already L1Infrastructure,
     * reachable from every layer, and already owns the sibling
     * mkgetdir()/secureDirectory() filesystem concerns.
     *
     * @return string[]
     */
    public static function getFsDirectories(string $path, bool $recursive = true): array
    {

        $dirs = [];
        $path = rtrim($path, '/');

        $sync_exclude_folders = \Piwigo\Config\Config::syncExcludeFolders();

        $exclude_folders = array_merge(
            $sync_exclude_folders,
            [
                '.', '..', '.svn',
                'thumbnail', 'pwg_high',
                'pwg_representative',
                'pwg_format',
            ]
        );
        $exclude_folders = array_flip($exclude_folders);

        if (is_dir($path)) {
            if ((bool) ($contents = opendir($path))) {
                while (($node = readdir($contents)) !== false) {
                    if (is_dir($path . '/' . $node) and ! isset($exclude_folders[$node])) {
                        $dirs[] = $path . '/' . $node;
                        if ($recursive) {
                            $dirs = array_merge($dirs, self::getFsDirectories($path . '/' . $node));
                        }
                    }
                }
                closedir($contents);
            }
        }

        return $dirs;
    }

    /**
     * Returns an array containing sub-directories, excluding ".svn"
     *
     * @return string[]
     */
    public static function getDirs(string $directory): array
    {
        $sub_dirs = [];
        if ((bool) ($opendir = opendir($directory))) {
            while ((bool) ($file = readdir($opendir))) {
                if ($file !== '.'
                    and $file !== '..'
                    and is_dir($directory . '/' . $file)
                    and $file !== '.svn') {
                    $sub_dirs[] = $file;
                }
            }
            closedir($opendir);
        }
        return $sub_dirs;
    }

    /**
     * Recursively delete a directory.
     *
     * @param ?string $trash_path try to move the directory to this path if it cannot be deleted
     */
    public static function deltree(string $path, ?string $trash_path = null): ?bool
    {
        if (is_dir($path)) {
            $fh = opendir($path);
            if ($fh !== false) {
                while ((bool) ($file = readdir($fh))) {
                    if ($file !== '.' and $file !== '..') {
                        $pathfile = $path . '/' . $file;
                        if (is_dir($pathfile)) {
                            self::deltree($pathfile, $trash_path);
                        } else {
                            @unlink($pathfile);
                        }
                    }
                }
                closedir($fh);
            }

            if (@rmdir($path)) {
                return true;
            }
            if ($trash_path !== null && $trash_path !== '') {
                if (! is_dir($trash_path)) {
                    @self::mkgetdir($trash_path, self::MKGETDIR_RECURSIVE | self::MKGETDIR_DIE_ON_ERROR | self::MKGETDIR_PROTECT_HTACCESS);
                }
                while ((bool) ($r = $trash_path . '/' . md5(uniqid((string) mt_rand(), true)))) {
                    if (! is_dir($r)) {
                        @rename($path, $r);
                        break;
                    }
                }

                return null;
            } else {
                return false;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public static function getCacheSizeDerivatives(string $path): array
    {
        $msizes = []; // final res

        if (is_dir($path)) {
            if ((bool) ($contents = opendir($path))) {
                while (($node = readdir($contents)) !== false) {
                    if ($node === '.' or $node === '..') {
                        continue;
                    }

                    if (is_file($path . '/' . $node)) {
                        $split = explode('-', $node);
                        $size_code = substr(end($split), 0, 2);
                        $file_size = filesize($path . '/' . $node);
                        $file_size = $file_size === false ? 0 : $file_size;
                        $msizes[$size_code] = ($msizes[$size_code] ?? 0) + $file_size;
                    } elseif (is_dir($path . '/' . $node)) {
                        $tmp_msizes = self::getCacheSizeDerivatives($path . '/' . $node);
                        foreach ($tmp_msizes as $size_key => $value) {
                            $msizes[$size_key] = ($msizes[$size_key] ?? 0) + $value;
                        }
                    }
                }
                closedir($contents);
            }
        }
        return $msizes;
    }
}
