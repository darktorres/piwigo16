<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\AdvisorySessionLock;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageUniquenessColumn;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.add` -- admin only. Adds an image.
 * `pwg.images.addChunk` must have been called before (maybe several
 * times). Don't use `thumbnail_sum`/`high_sum`, these parameters are
 * here for backward compatibility.
 *
 * Every registered field is always present (either mandatory --
 * `original_sum` -- or a real/null default), matching the shape below;
 * this method's own interdependent isset()/mutation logic (e.g.
 * `check_uniqueness`-gated advisory-lock acquisition) doesn't benefit
 * from a dedicated Params DTO the way a fixed-shape method would, so
 * this reads a local `@var`-narrowed copy directly, same as
 * `Images\SetInfoHandler`'s own documented rationale.
 */
final readonly class AddHandler implements WsAction
{
    /**
     * Advisory-lock acquisition timeout for the check_uniqueness race fix
     * below -- generous enough to cover a concurrent upload's own full
     * image-processing pipeline (resize, representative generation)
     * rather than just a DB round-trip.
     */
    private const int UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UploadService $uploadService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private DbCredentials $dbCredentials,
        private ChunkedUploadHelper $chunkedUploadHelper,
        private ImageCategoryRelationsHelper $imageCategoryRelationsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{image_id: int|string, url: string}
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead.
        /** @var array{thumbnail_sum: string|null, high_sum: string|null, original_sum: string, original_filename: string|null, name: string|null, author: string|null, date_creation: string|null, comment: string|null, categories: string|null, tag_ids: string|null, level: int, check_uniqueness: bool, image_id: int|null, ...} */
        $params = $params;

        $logger = $this->currentLogger->get();

        foreach ($params as $param_key => $param_value) {
            $logger->debug(sprintf(
                '[pwg.images.add] input param "%s" : "%s"',
                $param_key,
                is_scalar($param_value) ? (string) $param_value : 'NULL'
            ), 'WS');
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new WsErrorResponse(404, 'image_id not found');
            }
        }

        // does the image already exists ?
        // $params['original_sum']/original_filename are bound as query
        // parameters via existsWithColumnValue(), not spliced into SQL.
        //
        // Neither images.md5sum nor .file is indexed, and the
        // check-then-insert sequence below has a time-of-check-to-time-of-use
        // race (two concurrent uploads of the same value could both pass
        // this check before either INSERT completes). A MySQL advisory
        // lock, scoped to the specific column/value being uploaded and held
        // from this check through addUploadedFile()'s completion, closes
        // the race without affecting the check_uniqueness=false path (the
        // lock is only ever acquired inside this same `if`, so a caller
        // that opts out of the uniqueness check never touches it).
        $uniqueness_lock_conn = null;
        $uniqueness_lock_name = null;

        if ($params['check_uniqueness']) {
            $uniqueness_column = match ($this->currentConfig->uniquenessMode) {
                'md5sum' => 'md5sum',
                'filename' => 'file',
                default => null, // no known uniqueness_mode: skip the uniqueness check
            };

            if ($uniqueness_column !== null) {
                $uniqueness_value = $uniqueness_column === 'md5sum' ? $params['original_sum'] : ($params['original_filename'] ?? '');

                $uniqueness_lock_conn = DbConnection::build();
                // GET_LOCK() names are capped at 64 characters -- $uniqueness_value
                // is a caller-supplied filename in the 'file' uniqueness mode (up to
                // images.file's own 255-char width), so it's hashed rather
                // than concatenated literally. $this->dbCredentials->database is
                // folded into the hashed input so it still contributes to
                // collision-avoidance against unrelated applications on a shared
                // MySQL server.
                $uniqueness_lock_name = 'piwigo_iu_' . sha1($this->dbCredentials->database . ':' . $uniqueness_column . ':' . $uniqueness_value);
                $uniqueness_lock_ok = AdvisorySessionLock::acquire(
                    $uniqueness_lock_conn,
                    $uniqueness_lock_name,
                    self::UPLOAD_UNIQUENESS_LOCK_TIMEOUT_SECONDS
                );

                // A failed/timed-out acquisition means another request is right
                // now checking or inserting this exact value -- treated the same
                // as "file already exists" rather than silently proceeding
                // unprotected, since that concurrent request is the same
                // condition this check exists to catch.
                if (! $uniqueness_lock_ok || $this->imageService->existsWithColumnValue(
                    $uniqueness_column === 'md5sum' ? ImageUniquenessColumn::Md5sum : ImageUniquenessColumn::File,
                    $uniqueness_value
                )) {
                    if ($uniqueness_lock_ok) {
                        AdvisorySessionLock::release($uniqueness_lock_conn, $uniqueness_lock_name);
                    }
                    return new WsErrorResponse(500, 'file already exists');
                }
            }
        }

        // due to the new feature "derivatives" (multiple sizes) introduced for
        // Piwigo 2.4, we only take the biggest photos sent on
        // pwg.images.addChunk. If "high" is available we use it as "original"
        // else we use "file".
        $this->chunkedUploadHelper->removeChunks($params['original_sum'], 'thumb');

        if (isset($params['high_sum'])) {
            $original_type = 'high';
            $this->chunkedUploadHelper->removeChunks($params['original_sum'], 'file');
        } else {
            $original_type = 'file';
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $file_path = $upload_dir_conf . '/buffer/' . $params['original_sum'] . '-original';

        $this->chunkedUploadHelper->mergeChunks($file_path, $params['original_sum'], $original_type);
        chmod($file_path, 0644);

        try {
            $image_id = $this->uploadService
                ->addUploadedFile(
                    $file_path,
                    $this->urlService,
                    $params['original_filename'],
                    null, // categories
                    $params['level'],
                    $params['image_id'] > 0 ? $params['image_id'] : null,
                    $params['original_sum'],
                    $server
                );
        } finally {
            // $uniqueness_lock_name is always assigned in the same branch as
            // $uniqueness_lock_conn (see above), so checking the connection
            // alone is sufficient -- PHPStan proves this itself, flagging a
            // separate null-check on the name as redundant.
            if ($uniqueness_lock_conn instanceof Connection) {
                AdvisorySessionLock::release($uniqueness_lock_conn, $uniqueness_lock_name);
            }
        }

        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation']) ? $params['date_creation'] : null,
        );

        $url_params = [
            'image_id' => $image_id,
        ];

        // let's add links between the image and the categories
        if (isset($params['categories'])) {
            $this->imageCategoryRelationsHelper->addImageCategoryRelations(ImageId::from($image_id), $params['categories']);

            if ((bool) preg_match('/^\d+/', $params['categories'], $matches)) {
                $category_id = $matches[0];

                $category = $this->categoryService->getIdNamePermalinkById((int) $category_id);

                $url_params['section'] = 'categories';
                $url_params['category'] = $category;
            }
        }

        // and now, let's create tag associations
        if (isset($params['tag_ids']) and $params['tag_ids'] !== '') {
            $this->tagService
                ->setTags(
                    array_values(array_filter(array_map(TagId::tryFrom(...), explode(',', $params['tag_ids'])))),
                    $image_id
                );
        }

        PermissionCacheInvalidator::invalidate();

        return [
            'image_id' => $image_id,
            'url' => $this->urlService
                ->makePictureUrl($url_params),
        ];
    }
}
