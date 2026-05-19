<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\Paths;
use Piwigo\Core\StringUtil;
use Piwigo\Image\ImageRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.images.uploadAsync` — out-of-order chunked upload with MD5 checksums per chunk and on the merged file. */
final readonly class UploadAsyncHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
        private Paths $paths,
        private TagAdminService $tagAdminService,
        private UploadService $uploadService,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $logger       = LoggerRegistry::current();
        $pOriginalSum = is_string($params['original_sum'] ?? null) ? $params['original_sum'] : '';
        if (!preg_match('/^[a-fA-F0-9]{32}$/', $pOriginalSum)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid original_sum');
        }
        $pImageIdAsync = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pChunk        = is_numeric($params['chunk']) ? (int) $params['chunk'] : 0;
        $pChunks       = is_numeric($params['chunks']) ? (int) $params['chunks'] : 0;
        if ($pImageIdAsync > 0 && !$this->imageRepository->existsById($pImageIdAsync)) {
            return new PwgError(404, __FUNCTION__ . ' : image_id not found');
        }
        $pUserId               = (string) CurrentUser::get()->id;
        $outputFilepathPrefix  = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-u' . $pUserId;
        $chunkfilePathPattern  = $outputFilepathPrefix . '-%03uof%03u.chunk';
        $chunkfilePath         = sprintf($chunkfilePathPattern, $pChunk + 1, $pChunks);
        if (!Filesystem::mkgetdir(dirname($chunkfilePath), Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        StringUtil::secureDirectory(dirname($chunkfilePath));
        $filesFile2RawArr  = $_FILES['file'] ?? null;
        $filesFile2        = is_array($filesFile2RawArr) ? $filesFile2RawArr : [];
        $filesFile2TmpRaw  = $filesFile2['tmp_name'] ?? null;
        $filesFile2TmpName = is_string($filesFile2TmpRaw) ? $filesFile2TmpRaw : '';
        $chunkRoot         = $this->paths->root . Config::uploadDir();
        $chunkAbsPath      = $this->paths->root . ltrim(str_replace(['\\', '/./'], ['/', '/'], $chunkfilePath), '/');
        $chunkRelPath      = StorageRegistry::stripRoot($chunkRoot, $chunkAbsPath);
        $chunkStream       = fopen($filesFile2TmpName, 'rb');
        if ($chunkStream !== false) {
            StorageRegistry::disk('uploads')->writeStream($chunkRelPath, $chunkStream);
            fclose($chunkStream);
        }
        $logger->debug(__FUNCTION__ . ' uploaded ' . $chunkfilePath);
        $chunkMd5  = md5_file($chunkfilePath);
        $pChunkSum = is_string($params['chunk_sum'] ?? null) ? $params['chunk_sum'] : '';
        if ($chunkMd5 !== $pChunkSum) {
            unlink($chunkfilePath);
            $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' MD5 checksum mismatched');
            return new PwgError(500, 'MD5 checksum chunk file mismatched');
        }
        $chunkIdsUploaded = [];
        for ($i = 1; $i <= $pChunks; $i++) {
            $chunkfile = sprintf($chunkfilePathPattern, $i, $pChunks);
            if (file_exists($chunkfile) && ($fp = fopen($chunkfile, 'rb')) !== false) {
                $chunkIdsUploaded[] = $i;
                fclose($fp);
            }
        }
        if ($pChunks !== count($chunkIdsUploaded)) {
            $logger->debug(__FUNCTION__ . ' all chunks are not uploaded yet, exit for now');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $logger->debug(__FUNCTION__ . ' ' . $pOriginalSum . ' ' . $pChunks . ' chunks available, try now to get lock');
        $outputFilepath = $outputFilepathPrefix . '.merged';
        if (file_exists($outputFilepath) && ($fp = fopen($outputFilepath, 'rb')) !== false) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' already exists');
            return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
        }
        $fp = fopen($outputFilepath, 'wb');
        if (!$fp) {
            $logger->error(__FUNCTION__ . ' unable to create merge file');
            return new PwgError(500, 'error while creating merged ' . $chunkfilePath);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $logger->error(__FUNCTION__ . ' unable to obtain lock');
            return new PwgError(500, 'error while locking merged ' . $chunkfilePath);
        }
        $logger->debug(__FUNCTION__ . ' lock obtained to merge chunks');
        foreach ($chunkIdsUploaded as $chunkId) {
            $chunkfilePath = sprintf($chunkfilePathPattern, $chunkId, is_numeric($params['chunks']) ? (int) $params['chunks'] : 0);
            if (!file_exists($chunkfilePath)) {
                $logger->error(__FUNCTION__ . ' ' . $chunkfilePath . ' already merged');
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['message' => 'chunks uploaded = ' . implode(',', $chunkIdsUploaded)];
            }
            $chunkdata = file_get_contents($chunkfilePath);
            if ($chunkdata === false || fwrite($fp, $chunkdata) === false) {
                $logger->error(__FUNCTION__ . ' error merging chunk ' . $chunkfilePath);
                flock($fp, LOCK_UN);
                fclose($fp);
                Filesystem::tryUnlink($outputFilepath);
                return new PwgError(500, 'error while merging chunk ' . $chunkId);
            }
            $logger->debug(__FUNCTION__ . ' original_sum=' . $pOriginalSum . ', chunk ' . $chunkId . '/' . $pChunks . ' merged');
            unlink($chunkfilePath);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $logger->debug(__FUNCTION__ . ' merged file ' . $outputFilepath . ' saved');
        $mergedMd5 = md5_file($outputFilepath);
        if ($mergedMd5 !== $pOriginalSum) {
            unlink($outputFilepath);
            $logger->error(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum mismatched!');
            return new PwgError(500, 'MD5 checksum merged file mismatched');
        }
        $logger->debug(__FUNCTION__ . ' ' . $outputFilepath . ' MD5 checksum OK');
        $pFilename      = is_scalar($params['filename']) ? (string) $params['filename'] : null;
        $pCategoryAsync = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['category']) ? $params['category'] : []);
        $pLevelAsync    = is_numeric($params['level']) ? (int) $params['level'] : null;
        $pImageIdUpload = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        $imageId        = $this->uploadService->addUploadedFile($outputFilepath, $pFilename, $pCategoryAsync, $pLevelAsync, $pImageIdUpload, $pOriginalSum);
        $logger->debug(__FUNCTION__ . ' image_id after add_uploaded_file = ' . $imageId);
        if (isset($params['tag_ids']) && $params['tag_ids'] !== '') {
            $this->tagAdminService->setTags(explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : ''), $imageId);
        }
        $update = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        $this->userAdminService->invalidateUserCache();
        if (CurrentUser::isInitialized() && !empty($params['level']) && $params['level'] > (CurrentUser::get()->rawAttributes['level'] ?? 0)) {
            CurrentUser::get()->rawAttributes['level'] = $params['level'];
        }
        $now              = time();
        $globBufferResult = glob(Config::uploadDir() . '/buffer/' . '*.chunk');
        foreach ($globBufferResult !== false ? $globBufferResult : [] as $file) {
            $mtime = filemtime($file);
            if (is_file($file) && $mtime !== false && $now - $mtime >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        foreach ((($mergedGlob = glob(Config::uploadDir() . '/buffer/' . '*.merged')) !== false ? $mergedGlob : []) as $file) {
            $mtime = filemtime($file);
            if (is_file($file) && $mtime !== false && $now - $mtime >= 60 * 60 * 24 * 7) {
                unlink($file);
            }
        }
        return $server->invoke('pwg.images.getInfo', ['image_id' => $imageId]);
    }
}
