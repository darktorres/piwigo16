<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Site;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringHelper;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;

// provides data for site synchronization from the local file system
final class LocalSiteReader
{
    /**
     * @var array<string, int>
     */
    private readonly array $flip_file_ext;

    /**
     * @var array<string, int>
     */
    private readonly array $flip_picture_ext;

    public function __construct(
        public string $site_url,
        private readonly CurrentConfig $currentConfig,
        private readonly ?MetadataService $metadataService = null,
    ) {
        // Legacy Coupling Retirement Track A batch A4: was memoized on the
        // $conf global (flip_file_ext/flip_picture_ext never DB-persist --
        // pure per-instance derived state), now a private property computed
        // once per instance instead.
        $this->flip_file_ext = array_flip($this->currentConfig->fileExtensions());
        $this->flip_picture_ext = array_flip($this->currentConfig->pictureExtensions());
    }

    /**
     * Optional-with-lazy-default -- only the 2 metadata-sync methods below
     * reach this dependency, and both real callers construct this class
     * with just a site URL.
     */
    private function metadataService(): MetadataService
    {
        return $this->metadataService
            ?? new MetadataService($this->lang(), new MetadataRepository(EntityManagerFactory::build(DbConnection::build())), new CurrentLogger(), $this->eventDispatcher(), $this->currentConfig, new CurrentUser($this->currentConfig), $this->sessionService(), new FilterState(), $this->paths());
    }

    /**
     * Same reasoning as lang() above -- matches CurrentPaths::get()'s own
     * identical no-graceful-fallback shape (singleton/service-locator
     * elimination campaign, Phase 11 sub-phase 11H).
     */
    private function paths(): \Piwigo\Core\Paths
    {
        $paths = Kernel::container()->get(\Piwigo\Core\Paths::class);
        if (! $paths instanceof \Piwigo\Core\Paths) {
            throw new LogicException('Container returned an unexpected type for ' . \Piwigo\Core\Paths::class);
        }

        return $paths;
    }

    /**
     * Container resolve, not a constructor property -- used only inside
     * metadataService()'s own lazy-default fallback above. A required
     * constructor param here would break this class's own "both real
     * callers construct with just a site URL" simplicity (singleton/
     * service-locator elimination campaign, Phase 11 sub-phase 11G).
     */
    private function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * Same reasoning as lang() above -- gracefully falls back when
     * Kernel::boot() hasn't run, matching EventDispatcher::get()'s own
     * identical pre-boot fallback (unlike lang()/Lang::current(), which
     * has no safe default and always throws).
     */
    private function eventDispatcher(): EventDispatcher
    {
        if (Kernel::isBooted()) {
            $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
            if (! $eventDispatcher instanceof EventDispatcher) {
                throw new LogicException('Container returned an unexpected type for ' . EventDispatcher::class);
            }

            return $eventDispatcher;
        }

        return new EventDispatcher();
    }

    /**
     * Same reasoning as eventDispatcher() above -- SessionService::get()
     * has its own identical pre-boot fallback too.
     */
    private function sessionService(): SessionService
    {
        if (Kernel::isBooted()) {
            $sessionService = Kernel::container()->get(SessionService::class);
            if (! $sessionService instanceof SessionService) {
                throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
            }

            return $sessionService;
        }

        return new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig());
    }

    /**
     * Is this local site ok ?
     *
     * @return bool true on success, false otherwise
     */
    public function open(): bool
    {
        if (! is_dir($this->site_url)) {
            return false;
        }

        return true;
    }

    // retrieve file system sub-directories fulldirs
    /**
     * @return string[]
     */
    public function get_full_directories(string $basedir): array
    {
        $fs_fulldirs = FilesystemHelper::getFsDirectories($basedir);
        return $fs_fulldirs;
    }

    /**
     * Returns an array with all file system files according to
     * CurrentConfig::fileExtensions() and CurrentConfig::pictureExtensions()
     * @param string $path recurse in this directory
     * @return array<string, array{representative_ext: ?string, formats?: array<string, float>}> like "pic.jpg"=>array('representative_ext'=>'jpg' ... )
     */
    public function get_elements($path): array
    {
        $flip_file_ext = $this->flip_file_ext;
        $flip_picture_ext = $this->flip_picture_ext;

        $subdirs = [];
        $fs = [];
        if (is_dir($path) && (bool) ($contents = opendir($path))) {
            while (($node = readdir($contents)) !== false) {
                if ($node === '.' or $node === '..') {
                    continue;
                }

                if (is_file($path . '/' . $node)) {
                    $extension = strtolower(StringHelper::getExtension($node));
                    $filename_wo_ext = StringHelper::getFilenameWoExtension($node);

                    if (isset($flip_file_ext[$extension])) {
                        $representative_ext = null;
                        if (! isset($flip_picture_ext[$extension])) {
                            $representative_ext = $this->get_representative_ext($path, $filename_wo_ext);
                        }

                        $fs[$path . '/' . $node] = [
                            'representative_ext' => $representative_ext,
                        ];

                        if ($this->currentConfig->isFormatsEnabled()) {
                            $fs[$path . '/' . $node]['formats'] = $this->get_formats($path, $filename_wo_ext);
                        }
                    }
                } elseif (is_dir($path . '/' . $node)
                         and $node !== 'pwg_high'
                         and $node !== 'pwg_representative'
                         and $node !== 'pwg_format'
                         and $node !== 'thumbnail') {
                    $subdirs[] = $node;
                }
            } // end while readdir
            closedir($contents);

            foreach ($subdirs as $subdir) {
                $tmp_fs = $this->get_elements($path . '/' . $subdir);
                $fs = array_merge($fs, $tmp_fs);
            }
            ksort($fs);
        } // end if is_dir
        return $fs;
    }

    // returns the name of the attributes that are supported for
    // files update/synchronization
    /**
     * @return string[]
     */
    public function get_update_attributes(): array
    {
        return ['representative_ext'];
    }

    /**
     * @return array{representative_ext: ?string}
     */
    public function get_element_update_attributes(string $file): array
    {
        $data = [];

        $filename = basename($file);
        $extension = StringHelper::getExtension($filename);

        $representative_ext = null;
        if (! isset($this->flip_picture_ext[$extension])) {
            $dirname = dirname($file);
            $filename_wo_ext = StringHelper::getFilenameWoExtension($filename);
            $representative_ext = $this->get_representative_ext($dirname, $filename_wo_ext);
        }

        $data['representative_ext'] = $representative_ext;
        return $data;
    }

    // returns the name of the attributes that are supported for
    // metadata update/synchronization according to configuration
    /**
     * @return string[]
     */
    public function get_metadata_attributes(): array
    {
        return $this->metadataService()
            ->getSyncMetadataAttributes();
    }

    // returns a hash of attributes (metadata+filesize+width,...) for file
    /**
     * Thin delegate to MetadataService::getSyncMetadata() -- see that
     * method's own docblock for why $infos/the return stay generic.
     *
     * @param array<string, mixed> $infos
     * @return array<string, mixed>|false
     */
    public function get_element_metadata(array $infos): array|false
    {
        return $this->metadataService()
            ->getSyncMetadata($infos);
    }

    // -------------------------------------------------- private functions --------
    public function get_representative_ext(string $path, string $filename_wo_ext): ?string
    {
        $base_test = $path . '/pwg_representative/' . $filename_wo_ext . '.';
        foreach ($this->currentConfig->pictureExtensions() as $ext) {
            $test = $base_test . $ext;
            if (is_file($test)) {
                return $ext;
            }
        }
        return null;
    }

    /**
     * @return array<string, float> keyed by format extension
     */
    public function get_formats(string $path, string $filename_wo_ext): array
    {
        $formats = [];

        $base_test = $path . '/pwg_format/' . $filename_wo_ext . '.';

        foreach ($this->currentConfig->formatExtensions() as $ext) {
            $test = $base_test . $ext;

            if (is_file($test)) {
                $test_filesize = filesize($test);
                if ($test_filesize !== false) {
                    $formats[$ext] = floor($test_filesize / 1024);
                }
            }
        }

        return $formats;
    }
}
