<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin;

/**
 * PHP FFI wrapper for Everything SDK v3 (voidtools).
 *
 * Provides indexed filesystem queries via Everything 1.5+'s native DLL,
 * replacing recursive opendir/readdir traversal with near-instant lookups.
 *
 * Requires: ext-ffi enabled, Everything3_x64.dll, Everything 1.5a+ running.
 */
final class EverythingSDK
{
    // Property IDs from Everything3.h — these are stable and will not change.
    private const PROPERTY_FULL_PATH = 240;
    private const PROPERTY_SIZE = 2;

    private const MAX_VIEWPORT = 10_000_000;
    private const PATH_BUF_SIZE = 2048;

    /**
     * FFI C declarations for the Everything SDK v3 UTF-8 API.
     *
     * We use void* for opaque handles (client, search state, result list).
     * On Windows x64, __stdcall and __cdecl are identical, so no calling
     * convention annotation is needed.
     */
    private const FFI_CDEF = <<<'C'
        typedef void* EClient;
        typedef void* ESearchState;
        typedef void* EResultList;

        EClient     Everything3_ConnectUTF8(const char *instance_name);
        ESearchState Everything3_CreateSearchState(void);

        int  Everything3_DestroyClient(EClient client);
        int  Everything3_DestroySearchState(ESearchState state);
        int  Everything3_DestroyResultList(EResultList result_list);

        int  Everything3_SetSearchTextUTF8(ESearchState state, const char *text);
        void Everything3_SetSearchViewportOffset(ESearchState state, size_t offset);
        void Everything3_SetSearchViewportCount(ESearchState state, size_t count);
        int  Everything3_AddSearchPropertyRequest(ESearchState state, uint32_t property_id);

        EResultList Everything3_Search(EClient client, ESearchState state);

        size_t   Everything3_GetResultListCount(EResultList result_list);
        size_t   Everything3_GetResultListViewportCount(EResultList result_list);
        size_t   Everything3_GetResultFullPathNameUTF8(EResultList result_list, size_t index, char *buf, size_t bufsize);
        uint64_t Everything3_GetResultPropertyUINT64(EResultList result_list, size_t index, uint32_t property_id);
        int      Everything3_IsFolderResult(EResultList result_list, size_t index);

        uint32_t Everything3_GetLastError(void);
        C;

    private \FFI $ffi;

    private string $instanceName;

    private function __construct(\FFI $ffi, string $instanceName)
    {
        $this->ffi = $ffi;
        $this->instanceName = $instanceName;
    }

    /**
     * Attempt to create an SDK instance.
     *
     * Returns null when the DLL can't be loaded, ext-ffi is missing,
     * or Everything is not running (connection test fails).
     */
    /** @var string|null Last error from create(), for diagnostics. */
    public static ?string $lastError = null;

    public static function create(string $dllPath, string $instanceName = '1.5a'): ?self
    {
        self::$lastError = null;

        if (! extension_loaded('ffi')) {
            self::$lastError = 'ext-ffi not loaded';
            return null;
        }

        $absPath = realpath($dllPath);

        if ($absPath === false) {
            self::$lastError = 'DLL not found: ' . $dllPath;
            return null;
        }

        try {
            $ffi = \FFI::cdef(self::FFI_CDEF, $absPath);
        } catch (\FFI\Exception $e) {
            self::$lastError = 'FFI::cdef failed: ' . $e->getMessage();
            return null;
        }

        // Verify Everything is actually running by testing the connection.
        try {
            $client = $ffi->Everything3_ConnectUTF8($instanceName);

            if (\FFI::isNull($client)) {
                self::$lastError = 'ConnectUTF8 returned null (Everything not running or IPC not accessible)';
                return null;
            }

            $ffi->Everything3_DestroyClient($client);
        } catch (\FFI\Exception $e) {
            self::$lastError = 'Connection test failed: ' . $e->getMessage();
            return null;
        }

        return new self($ffi, $instanceName);
    }

    /**
     * Query Everything and return matching paths (backslash, Windows format).
     *
     * @return list<string>
     */
    public function queryPaths(string $query): array
    {
        return array_column($this->executeSearch($query, false), 'path');
    }

    /**
     * Query Everything and return paths with file sizes in bytes.
     *
     * @return list<array{path: string, size_bytes: int}>
     */
    public function queryPathsWithSize(string $query): array
    {
        return $this->executeSearch($query, true);
    }

    // ------------------------------------------------------------------
    //  Internal
    // ------------------------------------------------------------------

    /**
     * @return list<array{path: string, size_bytes?: int}>
     */
    private function executeSearch(string $query, bool $includeSize): array
    {
        $client = null;
        $searchState = null;
        $resultList = null;

        try {
            $client = $this->ffi->Everything3_ConnectUTF8($this->instanceName);

            if ($this->isNullHandle($client)) {
                return [];
            }

            $searchState = $this->ffi->Everything3_CreateSearchState();

            if ($this->isNullHandle($searchState)) {
                return [];
            }

            $this->ffi->Everything3_SetSearchTextUTF8($searchState, $query);
            $this->ffi->Everything3_SetSearchViewportOffset($searchState, 0);
            $this->ffi->Everything3_SetSearchViewportCount($searchState, self::MAX_VIEWPORT);
            $this->ffi->Everything3_AddSearchPropertyRequest($searchState, self::PROPERTY_FULL_PATH);

            if ($includeSize) {
                $this->ffi->Everything3_AddSearchPropertyRequest($searchState, self::PROPERTY_SIZE);
            }

            $resultList = $this->ffi->Everything3_Search($client, $searchState);

            if ($this->isNullHandle($resultList)) {
                return [];
            }

            $count = $this->ffi->Everything3_GetResultListViewportCount($resultList);
            $buf = $this->ffi->new('char[' . self::PATH_BUF_SIZE . ']');
            $results = [];

            for ($i = 0; $i < $count; $i++) {
                $this->ffi->Everything3_GetResultFullPathNameUTF8(
                    $resultList,
                    $i,
                    $buf,
                    self::PATH_BUF_SIZE
                );
                $path = \FFI::string($buf);

                if ($includeSize) {
                    $sizeBytes = $this->ffi->Everything3_GetResultPropertyUINT64(
                        $resultList,
                        $i,
                        self::PROPERTY_SIZE
                    );
                    $results[] = ['path' => $path, 'size_bytes' => (int) $sizeBytes];
                } else {
                    $results[] = ['path' => $path];
                }
            }

            return $results;
        } catch (\FFI\Exception) {
            return [];
        } finally {
            $this->destroyHandle($resultList, 'Everything3_DestroyResultList');
            $this->destroyHandle($searchState, 'Everything3_DestroySearchState');
            $this->destroyHandle($client, 'Everything3_DestroyClient');
        }
    }

    private function isNullHandle(mixed $handle): bool
    {
        return $handle === null || \FFI::isNull($handle);
    }

    private function destroyHandle(mixed $handle, string $method): void
    {
        if ($handle === null) {
            return;
        }

        try {
            if (! \FFI::isNull($handle)) {
                $this->ffi->{$method}($handle);
            }
        } catch (\FFI\Exception) {
            // Best-effort cleanup.
        }
    }
}
