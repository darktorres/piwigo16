<?php

declare(strict_types=1);

// add_uploaded_file() (admin/include/functions_upload.inc.php) is a real,
// heavily side-effecting free function (thumbnail generation, DB insert)
// well beyond this phase's "thin handler delegate" scope to exercise for
// real -- see Piwigo\Job\Handler\BatchUploadHandler's own docblock.
// PHP resolves an unqualified call from inside `namespace
// Piwigo\Job\Handler` against that SAME namespace's function table
// before falling back to the global one, so declaring a same-named
// function here -- in `Piwigo\Job\Handler`, not global -- transparently
// intercepts BatchUploadHandler's own call without touching the real,
// global add_uploaded_file() used anywhere else in the app.
namespace Piwigo\Job\Handler {
    /**
     * @param array{
     *     source_filepath: string,
     *     original_filename: string|null,
     *     categories: list<int>|null,
     *     level: int|null,
     *     image_id: int|null,
     *     original_md5sum: string|null,
     * }|null $push set to append a call record; omit to just read the log
     * @return list<array{
     *     source_filepath: string,
     *     original_filename: string|null,
     *     categories: list<int>|null,
     *     level: int|null,
     *     image_id: int|null,
     *     original_md5sum: string|null,
     * }>
     */
    function batch_upload_handler_test_calls(?array $push = null): array
    {
        /**
         * @var list<array{
         *     source_filepath: string,
         *     original_filename: string|null,
         *     categories: list<int>|null,
         *     level: int|null,
         *     image_id: int|null,
         *     original_md5sum: string|null,
         * }>|null $calls
         */
        static $calls = null;
        $calls ??= [];

        if ($push !== null) {
            $calls[] = $push;
        }

        return $calls;
    }

    /**
     * @param ?list<int> $categories
     */
    function add_uploaded_file(
        string $source_filepath,
        ?string $original_filename = null,
        ?array $categories = null,
        ?int $level = null,
        ?int $image_id = null,
        ?string $original_md5sum = null,
    ): int {
        batch_upload_handler_test_calls([
            'source_filepath' => $source_filepath,
            'original_filename' => $original_filename,
            'categories' => $categories,
            'level' => $level,
            'image_id' => $image_id,
            'original_md5sum' => $original_md5sum,
        ]);

        return 42;
    }
}

namespace {
    use Piwigo\Job\BatchUploadJob;
    use Piwigo\Job\Handler\BatchUploadHandler;

    use function Piwigo\Job\Handler\batch_upload_handler_test_calls;

    test('__invoke forwards every job property to add_uploaded_file() in order and returns its result', function (): void {
        $handler = new BatchUploadHandler();

        $result = $handler(new BatchUploadJob(
            sourceFilepath: '/tmp/upload/staged.jpg',
            originalFilename: 'photo.jpg',
            categories: [3, 7],
            level: 2,
            imageId: null,
            originalMd5sum: 'abc123',
        ));

        expect($result)->toBe(42)
            ->and(batch_upload_handler_test_calls())->toBe([
                [
                    'source_filepath' => '/tmp/upload/staged.jpg',
                    'original_filename' => 'photo.jpg',
                    'categories' => [3, 7],
                    'level' => 2,
                    'image_id' => null,
                    'original_md5sum' => 'abc123',
                ],
            ]);
    });
}
