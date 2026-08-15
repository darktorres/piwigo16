<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use LogicException;
use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Image\ImageService;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Request\UploadedFileRequest;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.uploadAsync` -- adds a chunk of an image. Chunks don't
 * have to be uploaded in the right sort order. When the last chunk is
 * added, they get merged. Requires admin credentials: either with
 * username/password or header authorization with api key.
 *
 * @since 11
 *
 * `username`/`password` are registered params, but this method never
 * reads them -- they're already consumed earlier, in
 * `Bootstrap/UserBootstrap.php`, to authenticate the request (a much
 * better time/place than here), so there's nothing to read them into.
 * Every other registered field is always present (either a real default
 * or mandatory), matching the shape below -- this method's whole shape
 * doesn't benefit from a dedicated Params DTO the way a fixed-shape
 * method would, so this reads a local `@var`-narrowed copy directly,
 * same as `Images\AddHandler`'s own documented rationale.
 */
final readonly class UploadAsyncHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private TagService $tagService,
        private UploadService $uploadService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private CurrentUser $currentUser,
        private Paths $paths,
        private StorageRegistry $storageRegistry,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<array-key, mixed> WsErrorResponse, an in-progress
     *   {message: string} status while chunks are still arriving, or the
     *   result of the pwg.images.getInfo invocation once the upload is
     *   complete
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead.
        /** @var array{chunk: int, chunk_sum: string, chunks: int, original_sum: string, category: array<int, int>, filename: string, name: string|null, author: string|null, comment: string|null, date_creation: string|null, level: int, tag_ids: string|null, image_id: int|null, ...} */
        $params = $params;

        $logger = $this->currentLogger->get();

        // additional check for some parameters
        if (! (bool) preg_match('/^[a-fA-F0-9]{32}$/', $params['original_sum'])) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid original_sum');
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new WsErrorResponse(404, 'uploadAsync : image_id not found');
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $output_filepath_prefix = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-u' . $this->currentUser->get()->id->value;
        $chunkfile_path_pattern = $output_filepath_prefix . '-%03uof%03u.chunk';

        $chunkfile_path = sprintf($chunkfile_path_pattern, $params['chunk'] + 1, $params['chunks']);

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir(dirname($chunkfile_path), $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new WsErrorResponse(500, 'error during buffer directory creation');
        }
        FilesystemHelper::secureDirectory(dirname($chunkfile_path));

        // move uploaded file
        $uploaded_chunk_tmp_name = UploadedFileRequest::fromFilesKey('file')->tmpName;
        if ($uploaded_chunk_tmp_name === null) {
            return new WsErrorResponse(500, 'missing uploaded chunk file');
        }
        // $chunkfile_path is already absolute ($upload_dir_conf above
        // includes the $this->paths->root prefix) -- just normalize
        // backslashes/'/./' segments before stripRoot() can compute the
        // 'uploads' disk-relative path; everything downstream keeps using
        // the original absolute $chunkfile_path unchanged, since the
        // 'uploads' disk is rooted at the same real filesystem location.
        $paths = $this->paths;
        $chunk_root = $paths->root . $this->currentConfig->uploadDir;
        $chunk_abs_path = str_replace(['\\', '/./'], ['/', '/'], $chunkfile_path);
        $chunk_rel_path = StorageRegistry::stripRoot($chunk_root, $chunk_abs_path);
        $chunk_stream = fopen($uploaded_chunk_tmp_name, 'rb');
        if ($chunk_stream !== false) {
            $this->storageRegistry->get('uploads')
                ->writeStream($chunk_rel_path, $chunk_stream);
            fclose($chunk_stream);
        }
        $logger->debug('uploadAsync uploaded ' . $chunkfile_path);

        // MD5 checksum
        $chunk_md5 = md5_file($chunkfile_path);
        if ($chunk_md5 !== $params['chunk_sum']) {
            unlink($chunkfile_path);
            $logger->error('uploadAsync ' . $chunkfile_path . ' MD5 checksum mismatched');
            return new WsErrorResponse(500, 'MD5 checksum chunk file mismatched');
        }

        // are all chunks uploaded?
        $chunk_ids_uploaded = [];
        for ($i = 1; $i <= $params['chunks']; $i++) {
            $chunkfile = sprintf($chunkfile_path_pattern, $i, $params['chunks']);
            if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
                $chunk_ids_uploaded[] = $i;
                fclose($fp);
            }
        }

        if ($params['chunks'] !== count($chunk_ids_uploaded)) {
            // all chunks are not yet available
            $logger->debug('uploadAsync all chunks are not uploaded yet, maybe on next chunk, exit for now');
            return [
                'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
            ];
        }

        // all chunks available
        $logger->debug('uploadAsync ' . $params['original_sum'] . ' ' . $params['chunks'] . ' chunks available, try now to get lock for merging');
        $output_filepath = $output_filepath_prefix . '.merged';

        // chunks already being merged?
        if (file_exists($output_filepath) && ($fp = fopen($output_filepath, 'rb')) !== false) {
            // merge file already exists
            fclose($fp);
            $logger->error('uploadAsync ' . $output_filepath . ' already exists, another merge is under process');
            return [
                'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
            ];
        }

        // create merged and open it for writing only
        $fp = fopen($output_filepath, 'wb');
        if (! (bool) $fp) {
            // unable to create file and open it for writing only
            $logger->error('uploadAsync ' . $chunkfile_path . ' unable to create merge file');
            return new WsErrorResponse(500, 'error while creating merged ' . $chunkfile_path);
        }

        // acquire an exclusive lock and keep it until merge completes
        // this postpones another uploadAsync task running in another thread
        if (! flock($fp, LOCK_EX)) {
            // unable to obtain lock
            fclose($fp);
            $logger->error('uploadAsync ' . $chunkfile_path . ' unable to obtain lock');
            return new WsErrorResponse(500, 'error while locking merged ' . $chunkfile_path);
        }

        $logger->debug('uploadAsync lock obtained to merge chunks');

        // loop over all chunks
        foreach ($chunk_ids_uploaded as $chunk_id) {
            $chunkfile_path = sprintf($chunkfile_path_pattern, $chunk_id, $params['chunks']);

            // chunk deleted by preceding merge?
            if (! file_exists($chunkfile_path)) {
                // cancel merge
                $logger->error('uploadAsync ' . $chunkfile_path . ' already merged');
                flock($fp, LOCK_UN);
                fclose($fp);
                return [
                    'message' => 'chunks uploaded = ' . implode(',', $chunk_ids_uploaded),
                ];
            }

            $chunk_contents = file_get_contents($chunkfile_path);
            if ($chunk_contents === false || ! (bool) fwrite($fp, $chunk_contents)) {
                // could not append chunk
                $logger->error('uploadAsync error merging chunk ' . $chunkfile_path);
                flock($fp, LOCK_UN);
                fclose($fp);

                // delete merge file without returning an error
                @unlink($output_filepath);
                return new WsErrorResponse(500, 'error while merging chunk ' . $chunk_id);
            }

            $logger->debug('uploadAsync original_sum=' . $params['original_sum'] . ', chunk ' . $chunk_id . '/' . $params['chunks'] . ' merged');

            // delete chunk and clear cache
            unlink($chunkfile_path);
        }

        // flush output before releasing lock
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $logger->debug('uploadAsync merged file ' . $output_filepath . ' saved');

        // MD5 checksum
        $merged_md5 = md5_file($output_filepath);

        if ($merged_md5 !== $params['original_sum']) {
            unlink($output_filepath);
            $logger->error('uploadAsync ' . $output_filepath . ' MD5 checksum mismatched!');
            return new WsErrorResponse(500, 'MD5 checksum merged file mismatched');
        }

        $logger->debug('uploadAsync ' . $output_filepath . ' MD5 checksum OK');

        $image_id = $this->uploadService
            ->addUploadedFile(
                $output_filepath,
                $this->urlService,
                $params['filename'],
                $params['category'],
                $params['level'],
                $params['image_id'],
                $params['original_sum'],
                $server
            );

        $logger->debug('uploadAsync image_id after add_uploaded_file = ' . $image_id);

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and $params['tag_ids'] !== '') {
            $this->tagService
                ->setTags(
                    array_values(array_filter(array_map(TagId::tryFrom(...), explode(',', $params['tag_ids'])))),
                    $image_id
                );
        }

        // time to set other infos
        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation']) ? $params['date_creation'] : null,
        );

        // final step, reset user cache
        PermissionCacheInvalidator::invalidate();

        // trick to bypass get_sql_condition_FandF
        if ($params['level'] !== 0 and $params['level'] > $this->currentUser->get()->level) {
            // this will not persist -- CurrentUser is the only reader of
            // this in-memory level override.
            $this->currentUser->set($this->currentUser->get()->withLevel($params['level']));
        }

        // delete chunks older than a week
        $now = time();
        $chunk_files = glob($upload_dir_conf . '/buffer/*.chunk');
        foreach (($chunk_files !== false ? $chunk_files : []) as $file) {
            if (is_file($file)) {
                $file_mtime = filemtime($file);
                // filemtime() can race with a concurrent cleanup pass removing
                // $file between the is_file() check above and here; skip it
                // this round rather than treat a failed stat as "old".
                if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                    $logger->info('uploadAsync delete ' . $file);
                    unlink($file);
                } else {
                    $logger->debug('uploadAsync keep ' . $file);
                }
            }
        }

        // delete merged older than a week
        $merged_files = glob($upload_dir_conf . '/buffer/*.merged');
        foreach (($merged_files !== false ? $merged_files : []) as $file) {
            if (is_file($file)) {
                $file_mtime = filemtime($file);
                // filemtime() can race with a concurrent cleanup pass removing
                // $file between the is_file() check above and here; skip it
                // this round rather than treat a failed stat as "old".
                if ($file_mtime !== false && $now - $file_mtime >= 60 * 60 * 24 * 7) { // 7 days
                    $logger->info('uploadAsync delete ' . $file);
                    unlink($file);
                } else {
                    $logger->debug('uploadAsync keep ' . $file);
                }
            }
        }

        $result = $server->invoke('pwg.images.getInfo', [
            'image_id' => $image_id,
        ]);
        // Server::invoke() is a genuine string-keyed dynamic dispatcher
        // (see Server's own class docblock) -- its declared return type
        // is `mixed` by design. This narrows it to the real shape this
        // specific sub-invocation (always 'pwg.images.getInfo', which
        // itself really does return WsErrorResponse|array<string, mixed>) is
        // known to return, the same "resolve, narrow, or throw" idiom
        // already used throughout this codebase for other statically-
        // unknowable-but-really-fixed-shape values (e.g. ImageBackend::
        // currentConfig()'s container resolve).
        if (! $result instanceof WsErrorResponse && ! is_array($result)) {
            throw new LogicException('pwg.images.getInfo returned an unexpected type');
        }

        return $result;
    }
}
