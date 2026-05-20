<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search\Rules;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\MalformedSearchRulesException;
use Piwigo\Search\Rules\AllwordsField;
use Piwigo\Search\Rules\AllwordsMode;
use Piwigo\Search\Rules\DatePresetCode;
use Piwigo\Search\Rules\SearchRules;

final class SearchRulesTest extends TestCase
{
    public function testFromArrayDefaultsToV1WhenVersionMissing(): void
    {
        $rules = SearchRules::fromArray(['fields' => ['expert' => ['string' => 'sunset OR beach']]]);
        self::assertSame(1, $rules->version);
        self::assertNotNull($rules->expert);
        self::assertSame('sunset OR beach', $rules->expert->query);
    }

    public function testFromArrayHonorsExplicitVersionAndParsesFields(): void
    {
        $rules = SearchRules::fromArray([
            'version' => 1,
            'fields'  => ['cat' => ['words' => [3, 7], 'sub_inc' => true]],
        ]);
        self::assertSame(1, $rules->version);
        self::assertNotNull($rules->cat);
        self::assertSame([3, 7], $rules->cat->categoryIds);
        self::assertTrue($rules->cat->subIncluded);
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

    public function testAllwordsParsesSpaceSeparatedString(): void
    {
        $rules = SearchRules::fromArray([
            'fields' => ['allwords' => ['words' => 'sunset beach', 'fields' => ['name', 'comment'], 'mode' => 'OR']],
        ]);
        self::assertNotNull($rules->allwords);
        self::assertSame(['sunset', 'beach'], $rules->allwords->words);
        self::assertSame([AllwordsField::Name, AllwordsField::Comment], $rules->allwords->fields);
        self::assertSame(AllwordsMode::Or, $rules->allwords->mode);
    }

    public function testAbsentBlocksDecodeToNull(): void
    {
        $rules = SearchRules::fromArray(['fields' => []]);
        self::assertNull($rules->expert);
        self::assertNull($rules->allwords);
        self::assertNull($rules->author);
        self::assertNull($rules->cat);
        self::assertNull($rules->datePosted);
        self::assertNull($rules->dateCreated);
        self::assertNull($rules->tags);
        self::assertNull($rules->filetypes);
        self::assertNull($rules->addedBy);
        self::assertNull($rules->ratio);
        self::assertNull($rules->rating);
        self::assertNull($rules->fileSize);
        self::assertNull($rules->height);
        self::assertNull($rules->width);
    }

    public function testDatePostedPresetAndCustom(): void
    {
        $rules = SearchRules::fromArray([
            'fields' => ['date_posted' => ['preset' => 'custom', 'custom' => ['y2024', 'm2023-08']]],
        ]);
        self::assertNotNull($rules->datePosted);
        self::assertSame(DatePresetCode::Custom, $rules->datePosted->preset);
        self::assertSame(['y2024', 'm2023-08'], $rules->datePosted->customCodes);
    }

    public function testRangeFiltersRequireBothBounds(): void
    {
        $rules = SearchRules::fromArray([
            'fields' => ['filesize_min' => 100, 'filesize_max' => 500],
        ]);
        self::assertNotNull($rules->fileSize);
        self::assertSame(100, $rules->fileSize->minKb);
        self::assertSame(500, $rules->fileSize->maxKb);

        $missing = SearchRules::fromArray(['fields' => ['filesize_min' => 100]]);
        self::assertNull($missing->fileSize);
    }
}
