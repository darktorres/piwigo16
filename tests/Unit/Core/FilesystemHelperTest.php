<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;

/**
 * getFsDirectories()/getDirs() already have full coverage through other
 * suites' real callers (e.g. admin sync flows) -- this file only closes the
 * remaining gaps: mkgetdir()'s failure/fatalError plumbing, deltree()'s
 * trash-path branches, and getCacheSizeDerivatives() (previously untested
 * end to end).
 *
 * mkgetdir()'s str_replace(DIRECTORY_SEPARATOR) branch (line 83) is gated
 * behind `str_starts_with(PHP_OS, 'WIN')`; PHP_OS is a compile-time
 * constant on this Linux CI/dev environment, so that branch is
 * unreachable here -- left uncovered, same as any other platform-gated
 * dead branch, not chased.
 *
 * FilesystemHelper::$htmlRenderer has only a setter (setHtmlRenderer()),
 * no public reset -- same static-setter shape as Piwigo\Core\Lang's own
 * htmlRenderer. Reflection sets/restores it directly in before/afterEach,
 * matching the reflection-seed convention already used by
 * tests/Unit/Core/ErrorCollectorTest.php for similar setter-only statics.
 */
function filesystemHelperTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    @chmod($dir, 0o755);
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        if (is_dir($path)) {
            filesystemHelperTestRrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function filesystemHelperTestSetRenderer(?HtmlRenderingInterface $renderer): void
{
    $prop = new ReflectionProperty(FilesystemHelper::class, 'htmlRenderer');
    $prop->setValue(null, $renderer);
}

/**
 * Fake HtmlRenderingInterface whose fatalError() records the message into
 * the caller-supplied $capture object and throws a plain \RuntimeException
 * with a distinguishable prefix (rather than FilesystemHelper's own
 * default \RuntimeException fallback) -- lets the "delegates to the
 * installed renderer" test tell the two paths apart by message alone. The
 * message is written onto an external \stdClass rather than a property
 * on the anonymous class itself so this factory can keep a plain
 * HtmlRenderingInterface return type without losing readability of the
 * captured value afterwards.
 */
function filesystemHelperTestMakeFatalRenderer(\stdClass $capture): HtmlRenderingInterface
{
    return new class($capture) implements HtmlRenderingInterface {
        public function __construct(private readonly \stdClass $capture)
        {
        }

        public function getCatDisplayName(array $catInformations, ?string $url = ''): string
        {
            return '';
        }

        public function getCatDisplayNameCache(
            string $uppercats,
            ?string $url = '',
            bool $singleLink = false,
            ?string $linkClass = null,
            ?string $authKey = null,
        ): string {
            return '';
        }

        public function nameCompare(array $a, array $b): int
        {
            return 0;
        }

        public function tagAlphaCompare(array $a, array $b): int
        {
            return 0;
        }

        public function accessDenied(\Piwigo\Core\RedirectServiceInterface $redirectService): never
        {
            throw new \RuntimeException('accessDenied');
        }

        public function badRequest(\Piwigo\Core\RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new \RuntimeException('badRequest');
        }

        public function pageNotFound(\Piwigo\Core\RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
        {
            throw new \RuntimeException('pageNotFound');
        }

        public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
        {
            $this->capture->lastMessage = $msg;

            throw new \RuntimeException('renderer-fatal:' . $msg);
        }

        public function getTagsContentTitle(array $tags): string
        {
            return '';
        }

        public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
        {
            return '';
        }

        public function setStatusHeader(int $code, string $text = ''): void
        {
        }

        public function renderElementName(array $info): string
        {
            return '';
        }

        public function renderElementDescription(array $info, string $param = ''): string
        {
            return '';
        }

        public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
        {
            return '';
        }
    };
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-filesystemhelper-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
    CurrentConfig::reset();
});

afterEach(function (): void {
    filesystemHelperTestSetRenderer(null);
    filesystemHelperTestRrmdir(is_string($this->root) ? $this->root : '');
    CurrentConfig::reset();
});

test('mkgetdir creates a new directory and protects it with index.htm under the default flags', function (): void {
    $dir = $this->root . '/gallery';

    expect(FilesystemHelper::mkgetdir($dir))->toBeTrue();

    expect(is_dir($dir))->toBeTrue()
        ->and(file_get_contents($dir . '/index.htm'))->toBe('Not allowed!')
        ->and(file_exists($dir . '/.htaccess'))->toBeFalse();
});

test('mkgetdir with MKGETDIR_PROTECT_HTACCESS writes a deny-from-all .htaccess into a freshly-created recursive directory', function (): void {
    $dir = $this->root . '/a/b/c';

    $result = FilesystemHelper::mkgetdir(
        $dir,
        FilesystemHelper::MKGETDIR_RECURSIVE | FilesystemHelper::MKGETDIR_PROTECT_HTACCESS,
    );

    expect($result)->toBeTrue()
        ->and(is_dir($dir))->toBeTrue()
        ->and(file_get_contents($dir . '/.htaccess'))->toBe('deny from all')
        ->and(file_exists($dir . '/index.htm'))->toBeFalse();
});

test('mkgetdir returns false without throwing when the target cannot be created and MKGETDIR_DIE_ON_ERROR is not set', function (): void {
    $parent = $this->root . '/locked-parent';
    mkdir($parent);
    chmod($parent, 0o555);
    $dir = $parent . '/child';

    expect(FilesystemHelper::mkgetdir($dir, FilesystemHelper::MKGETDIR_NONE))->toBeFalse();
    expect(is_dir($dir))->toBeFalse();
});

test('mkgetdir throws a RuntimeException carrying the untranslated message when creation fails and MKGETDIR_DIE_ON_ERROR is set with no html renderer installed', function (): void {
    $parent = $this->root . '/locked-parent-2';
    mkdir($parent);
    chmod($parent, 0o555);
    $dir = $parent . '/child';

    expect(fn () => FilesystemHelper::mkgetdir($dir, FilesystemHelper::MKGETDIR_DIE_ON_ERROR))
        ->toThrow(\RuntimeException::class, $dir . ' no write access');
});

test('mkgetdir delegates the fatal message to the installed HtmlRenderingInterface instead of throwing RuntimeException directly', function (): void {
    $capture = new stdClass();
    filesystemHelperTestSetRenderer(filesystemHelperTestMakeFatalRenderer($capture));

    $parent = $this->root . '/locked-parent-3';
    mkdir($parent);
    chmod($parent, 0o555);
    $dir = $parent . '/child';

    expect(fn () => FilesystemHelper::mkgetdir($dir, FilesystemHelper::MKGETDIR_DIE_ON_ERROR))
        ->toThrow(\RuntimeException::class, 'renderer-fatal:' . $dir . ' no write access');

    expect($capture->lastMessage)->toBe($dir . ' no write access');
});

test('mkgetdir returns false when a freshly-created directory ends up non-writable and MKGETDIR_DIE_ON_ERROR is not set', function (): void {
    CurrentConfig::setChmodValue(0o500);
    $dir = $this->root . '/read-only-new';

    $result = FilesystemHelper::mkgetdir($dir, FilesystemHelper::MKGETDIR_RECURSIVE);

    expect(is_dir($dir))->toBeTrue()
        ->and(is_writable($dir))->toBeFalse()
        ->and($result)->toBeFalse();
});

test('mkgetdir throws when an already-existing directory has lost its write permission and MKGETDIR_DIE_ON_ERROR is set', function (): void {
    $dir = $this->root . '/already-there';
    mkdir($dir);
    chmod($dir, 0o500);

    expect(fn () => FilesystemHelper::mkgetdir($dir, FilesystemHelper::MKGETDIR_DIE_ON_ERROR))
        ->toThrow(\RuntimeException::class, $dir . ' no write access');
});

test('nearestExistingAncestor walks up to the dirname()-fixed-point root and stops there when no ancestor is ever visible', function (): void {
    // Every real ancestor on this actual filesystem (up to and including
    // '/') genuinely exists, so is_dir() always finds a stopping point
    // long before dirname() reaches its own fixed point ('/') -- the
    // `$parent === $dir` break is only reachable when is_dir() itself is
    // denied for every single ancestor, all the way up to '/'. A real
    // open_basedir restriction (confined to this project's own root) does
    // exactly that for a target path entirely outside it: is_dir() is
    // denied (not merely "returns false because missing") for every
    // ancestor, including '/' itself. Run in a genuine subprocess, same
    // convention as ContainerDetectorTest's own open_basedir test --
    // ini_set()-narrowing open_basedir can only be widened by restarting
    // the process, so this can't run inline in the shared worker. Bare
    // CLI subprocess coverage isn't ingested by this project's own
    // coverage-merge tooling (see that file's own docblock), but this is
    // still real, deterministic proof of the branch's behavior.
    $projectRoot = dirname(__DIR__, 3);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    expect(is_file($autoloadPath))->toBeTrue();

    $targetOutsideOpenBasedir = '/etc/definitely-not-a-real-piwigo-path/deep/nested/dir';
    $script = 'require ' . var_export($autoloadPath, true) . ';'
        . 'set_error_handler(static fn (): bool => true);'
        . '$method = new ReflectionMethod(\Piwigo\Core\FilesystemHelper::class, "nearestExistingAncestor");'
        . 'echo json_encode(["result" => $method->invoke(null, ' . var_export($targetOutsideOpenBasedir, true) . ')]);';

    $cmd = [
        PHP_BINARY,
        '-d', 'open_basedir=' . $projectRoot,
        '-r', $script,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    expect($proc)->toBeResource();
    if ($proc === false) {
        throw new RuntimeException('proc_open failed');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    expect($exit)->toBe(0, 'nearestExistingAncestor subprocess failed: ' . ($stderr === '' ? '(no stderr)' : $stderr));
    expect(json_decode($stdout, true))->toBe(['result' => '/']);
});

test('deltree returns null immediately for a path that is not a directory', function (): void {
    expect(FilesystemHelper::deltree($this->root . '/does-not-exist'))->toBeNull();
});

test('deltree returns false when rmdir fails and no trash_path is given', function (): void {
    $parent = $this->root . '/undeletable-parent';
    mkdir($parent);
    $victim = $parent . '/victim';
    mkdir($victim);
    file_put_contents($victim . '/leaf.txt', 'leaf contents');
    chmod($parent, 0o555);

    expect(FilesystemHelper::deltree($victim))->toBeFalse();

    chmod($parent, 0o755);
});

test('deltree takes the trash_path branch and returns null when rmdir fails, even though the rename itself is skipped due to the same locked parent', function (): void {
    // rename() needs write access on $path's *parent* directory to remove
    // its old entry -- the exact same permission rmdir() already failed
    // on above -- so deltree() checks that write access once and skips
    // attempting the rename entirely when the parent is locked (avoiding
    // a PHP-level warning, confirmed live: rename() into a locked-parent
    // source still emits "Permission denied" even when `@`-suppressed).
    // $victim is left in place (now empty, its own leaf file already
    // unlinked by the recursive pass, which only needed write access on
    // $victim itself, not its parent). This exercises every trash_path
    // line (the branch, the trash mkgetdir call, the unique-name loop,
    // the skipped-rename guard, the break, the null return) without
    // needing a parent directory that is simultaneously lockable and
    // unlockable.
    $parent = $this->root . '/undeletable-parent-2';
    mkdir($parent);
    $victim = $parent . '/victim';
    mkdir($victim);
    file_put_contents($victim . '/leaf.txt', 'leaf contents');
    chmod($parent, 0o555);
    $trash = $this->root . '/trash-bin';

    $result = FilesystemHelper::deltree($victim, $trash);

    chmod($parent, 0o755);

    expect($result)->toBeNull()
        ->and(is_dir($trash))->toBeTrue()
        ->and(is_dir($victim))->toBeTrue()
        ->and(file_exists($victim . '/leaf.txt'))->toBeFalse();

    $trashEntries = array_values(array_diff(scandir($trash) !== false ? scandir($trash) : [], ['.', '..']));
    expect($trashEntries)->toBe(['.htaccess']);
});

test('deltree actually renames an undeletable directory into the trash path when its own parent stays writable', function (): void {
    // The real-rename counterpart to the skipped-rename test above: rename()
    // needs write access on $victim's own parent (here left untouched,
    // 0755) plus write access on $victim itself -- NOT execute -- confirmed
    // live (chmod 0555, r-x, blocks rename(); chmod 0644, rw-, does not).
    // $victim is chmod'd rw- (no execute) so its own child can never be
    // unlink()'d (unlink needs write+execute on the *containing* dir) --
    // genuinely non-empty, so rmdir($victim) fails with ENOTEMPTY, not a
    // permission error, distinguishing this from the sibling test above.
    $victim = $this->root . '/undeletable-but-renamable';
    mkdir($victim);
    file_put_contents($victim . '/leaf.txt', 'leaf contents');
    chmod($victim, 0o644);
    $trash = $this->root . '/trash-bin-2';

    set_error_handler(static fn (): bool => true);
    try {
        $result = FilesystemHelper::deltree($victim, $trash);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeNull()
        ->and(is_dir($victim))->toBeFalse();

    $trashEntries = array_values(array_diff(scandir($trash) !== false ? scandir($trash) : [], ['.', '..', '.htaccess']));
    expect($trashEntries)->toHaveCount(1);
    $movedDir = $trash . '/' . $trashEntries[0];
    chmod($movedDir, 0o755); // restore so this test's own afterEach cleanup can traverse it
    expect(file_get_contents($movedDir . '/leaf.txt'))->toBe('leaf contents');
});

test('getCacheSizeDerivatives sums file sizes per two-character derivative code across nested directories', function (): void {
    $root = $this->root . '/cache-sizes';
    mkdir($root);
    mkdir($root . '/2024');
    file_put_contents($root . '/holiday-photo-sq.jpg', str_repeat('a', 100));
    file_put_contents($root . '/holiday-photo-th.jpg', str_repeat('b', 250));
    file_put_contents($root . '/2024/family-trip-sq.jpg', str_repeat('c', 30));
    file_put_contents($root . '/2024/family-trip-me.jpg', str_repeat('d', 900));

    $sizes = FilesystemHelper::getCacheSizeDerivatives($root);

    // Key order depends on scandir()'s real filesystem-dependent directory
    // listing order, not the files' creation order -- compare by content,
    // not key order.
    expect($sizes)->toEqualCanonicalizing([
        'sq' => 130,
        'th' => 250,
        'me' => 900,
    ]);
});

test('getCacheSizeDerivatives returns an empty array for a directory with no matching content and skips dot entries', function (): void {
    $root = $this->root . '/cache-sizes-empty';
    mkdir($root);

    expect(FilesystemHelper::getCacheSizeDerivatives($root))->toBe([]);
});

test('getCacheSizeDerivatives returns an empty array for a non-existent path', function (): void {
    expect(FilesystemHelper::getCacheSizeDerivatives($this->root . '/never-existed'))->toBe([]);
});
