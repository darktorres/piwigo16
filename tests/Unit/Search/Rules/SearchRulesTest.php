<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search\Rules;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\MalformedSearchRulesException;
use Piwigo\Search\Rules\SearchRules;

final class SearchRulesTest extends TestCase
{
    public function testFromArrayDefaultsToV1WhenVersionMissing(): void
    {
        $rules = SearchRules::fromArray(['expert' => 'sunset OR beach']);
        self::assertSame(1, $rules->version);
        self::assertSame(['expert' => 'sunset OR beach'], $rules->filters);
    }

    public function testFromArrayHonorsExplicitVersionAndStripsItFromFilters(): void
    {
        $rules = SearchRules::fromArray(['version' => 1, 'cat' => ['categories' => [3]]]);
        self::assertSame(1, $rules->version);
        self::assertArrayNotHasKey('version', $rules->filters);
    }

    public function testFromArrayRejectsUnknownVersion(): void
    {
        $this->expectException(MalformedSearchRulesException::class);
        SearchRules::fromArray(['version' => 99]);
    }

    public function testFromJsonRejectsNonObjectPayload(): void
    {
        $this->expectException(MalformedSearchRulesException::class);
        SearchRules::fromJson('"a string"');
    }

    public function testFromJsonRejectsListPayload(): void
    {
        $this->expectException(MalformedSearchRulesException::class);
        SearchRules::fromJson('[1, 2, 3]');
    }

    public function testToJsonRoundTrips(): void
    {
        $rules = SearchRules::fromArray(['allwords' => ['words' => ['sunset'], 'mode' => 'AND']]);
        $json  = $rules->toJson();
        $back  = SearchRules::fromJson($json);
        self::assertSame($rules->filters, $back->filters);
        self::assertSame(1, $back->version);
    }
}
