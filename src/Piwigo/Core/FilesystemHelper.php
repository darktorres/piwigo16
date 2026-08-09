<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;
use RuntimeException;

/**
 * MKGETDIR_ bitmask flags are defined as class constants (autoloaded with
 * the class itself), so mkgetdir()'s default argument is self-contained
 * regardless of bootstrap path.
 *
 * Every real method here (mkgetdir()/secureDirectory()/getFsDirectories()/
 * getDirs()/deltree()/getCacheSizeDerivatives()) is a pure static utility
 * function with no natural "construct once, call several times" shape --
 * there is no instance to speak of, so this class has no container-shared
 * instance of its own. `HtmlRenderingInterface`/`Lang` are each bound in
 * container.php, so this class's 2 private, internal collaborator reads
 * (fatalError()/t()) each resolve directly from the container instead --
 * this file is included in `Kernel::container()`'s `shimAllowedFiles`
 * allow-list to permit that.
 *
 * mkgetdir()/getFsDirectories() take CurrentConfig as a real, explicit
 * param rather than resolving it internally -- every real caller already
 * has one available. deltree()'s own internal trash_path mkgetdir() call
 * is the exception, resolving CurrentConfig itself (see currentConfig()
 * below), since threading a param through deltree()'s own public
 * signature would ripple into every one of its real callers too.
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

    private static function fatalError(string $msg): never
    {
        if (Kernel::isBooted()) {
            $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
            if ($htmlRenderer instanceof HtmlRenderingInterface) {
                $htmlRenderer->fatalError($msg);
            }
        }
        throw new RuntimeException($msg);
    }

    /**
     * Mirrors fatalError()'s own Kernel::isBooted() tolerance just above --
     * this class is a purely static utility (no wrapper instance, see this
     * class's own docblock) called from code paths that may run before
     * Kernel is booted at all (e.g. a cold-cache thumbnail generation via
     * i.php's lighter bootstrap). Lang has no pre-boot fallback (its
     * constructor needs real collaborators), so falling back to the raw,
     * untranslated key here matches Translator::translate()'s own "no data
     * loaded" fallback behavior. self::lang() is only ever reached once
     * this method's own isBooted() guard already holds, so its
     * unconditional container resolve never actually throws here.
     */
    private static function t(string $key): string
    {
        return Kernel::isBooted() ? self::lang()->t($key) : $key;
    }

    private static function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new RuntimeException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * mkgetdir()/getFsDirectories() take CurrentConfig as a real, explicit
     * param rather than resolving it here (this class's own docblock
     * above explains why). deltree()'s own internal trash_path mkgetdir()
     * call below is different: no test depends on a specific CurrentConfig
     * value reaching it, and threading a param through deltree()'s own
     * public signature would ripple into every one of ITS real callers,
     * not just mkgetdir()'s.
     */
    private static function currentConfig(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new RuntimeException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $currentConfig;
        }

        return new CurrentConfig();
    }

    /**
     * Walks up from $dir to the nearest ancestor that already exists, so
     * callers can check write access before attempting a recursive mkdir()
     * whose immediate parent may not exist yet.
     */
    private static function nearestExistingAncestor(string $dir): string
    {
        while (! is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return $dir;
    }

    /**
     * creates directory if not exists and ensures that directory is writable
     *
     * @param int $flags combination of self::MKGETDIR_xxx
     */
    public static function mkgetdir(string $dir, CurrentConfig $currentConfig, int $flags = self::MKGETDIR_DEFAULT): bool
    {
        if (! is_dir($dir)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            $chmod_value = $currentConfig->chmodValue;
            // Checking the nearest existing ancestor's write access before
            // calling mkdir() avoids a PHP-level warning on the deterministic
            // permission-denied case; a concurrent creation of the same
            // directory by another process is still handled below by the
            // is_dir() re-check, same as before. @-suppressed: that race
            // (another request wins EEXIST between the is_dir() check above
            // and this mkdir() call) is real and already treated as success,
            // not an error -- the native warning would just be noise.
            $mkd = is_writable(self::nearestExistingAncestor($dir))
                && @mkdir($dir, $chmod_value, ((bool) ($flags & self::MKGETDIR_RECURSIVE)) ? true : false);
            umask($umask);
            // Retest existence on mkdir() failure: concurrent requests (e.g.
            // parallel i.php derivative generations on a cold cache) race to
            // create the same directory, and the losers fail with EEXIST on
            // slow filesystems -- that is success, not an error (re-check
            // ported from HEAD i.php's local mkgetdir()).
            if ($mkd === false && ! is_dir($dir)) {
                ! (bool) ($flags & self::MKGETDIR_DIE_ON_ERROR) or self::fatalError("{$dir} " . self::t('no write access'));
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
            ! (bool) ($flags & self::MKGETDIR_DIE_ON_ERROR) or self::fatalError("{$dir} " . self::t('no write access'));
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
     * Lives here (not Piwigo\Admin) because a real caller,
     * Piwigo\Site\LocalSiteReader (L2bExtendedDomain), can't depend on
     * L4Integration -- this class is already L1Infrastructure, reachable
     * from every layer, and already owns the sibling
     * mkgetdir()/secureDirectory() filesystem concerns.
     *
     * @return string[]
     */
    public static function getFsDirectories(string $path, CurrentConfig $currentConfig, bool $recursive = true): array
    {

        $dirs = [];
        $path = rtrim($path, '/');

        $sync_exclude_folders = $currentConfig->syncExcludeFolders;

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
                            $dirs = array_merge($dirs, self::getFsDirectories($path . '/' . $node, $currentConfig));
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

            // Checking the parent directory's write access first avoids a
            // PHP-level warning on the deterministic permission-denied case
            // -- rename() needs the same write access on $path's parent to
            // remove its old entry, so a locked parent rules out both.
            $canModifyParent = is_writable(dirname($path));
            if ($canModifyParent && rmdir($path)) {
                return true;
            }
            if ($trash_path !== null && $trash_path !== '') {
                if (! is_dir($trash_path)) {
                    @self::mkgetdir($trash_path, self::currentConfig(), self::MKGETDIR_RECURSIVE | self::MKGETDIR_DIE_ON_ERROR | self::MKGETDIR_PROTECT_HTACCESS);
                }
                while ((bool) ($r = $trash_path . '/' . md5(uniqid((string) mt_rand(), true)))) {
                    if (! is_dir($r)) {
                        if ($canModifyParent) {
                            rename($path, $r);
                        }
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
