<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

/**
 * Ws\PwgImages::filteredSearchCreate()'s date_created_custom branches --
 * WsImagesFilteredSearchTest.php covers date_posted_custom's y/m/d format
 * parsing plus one date_created_custom 'd' case, but not
 * date_created_custom's own missing/wrong-preset guards or its y/m format
 * parsing (a near-identical block to date_posted_custom, but a genuinely
 * separate set of source lines).
 *
 * The 'tags'/'categories'/'added_by' "must only contain digits"
 * PwgError(INVALID_PARAM, 'Invalid parameter X') branches are NOT chased
 * here: all three are registered with 'type' => WsParamType::ID (see
 * WsDefaultMethods), so PwgServer::invoke() itself already rejects any
 * non-positive-integer element with its own "X must only contain positive
 * and not null integers" error *before* filteredSearchCreate() ever runs --
 * confirmed live via direct WS calls. filteredSearchCreate()'s own
 * `preg_match('/^\d+$/', ...)` re-check of those same values is therefore
 * unreachable dead code through the public WS route.
 */
final class WsImagesFilteredSearchDateCreatedTest extends ContractTestCase
{
    private const string METHOD = 'pwg.images.filteredSearch.create';

    public function testDateCreatedPresetCustomWithoutCustomValuesReturnsError(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_preset' => 'custom',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_created_custom is missing', $response['message']);
    }

    public function testDateCreatedCustomWithoutPresetCustomReturnsError(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_custom' => ['y2026'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_created_custom provided date_created_preset is not custom', $response['message']);
    }

    public function testDateCreatedCustomAcceptsYearAndMonthFormats(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_preset' => 'custom',
            'date_created_custom' => ['y2026', 'm2026-07'],
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertIsString($result['search_id']);
    }

    public function testDateCreatedCustomRejectsInvalidMonth(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_preset' => 'custom',
            'date_created_custom' => ['m2026-13'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_created_custom, invalid option m2026-13', $response['message']);
    }

    public function testDateCreatedCustomRejectsUnrecognizedPrefix(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_preset' => 'custom',
            'date_created_custom' => ['q2026'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_created_custom, invalid option q2026', $response['message']);
    }
}
