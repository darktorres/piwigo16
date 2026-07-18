<?php

declare(strict_types=1);

use Piwigo\Section\SectionContext;

test('constructor defaults match a categories-section homepage-shaped context', function (): void {
    $context = new SectionContext();

    expect($context->section)->toBe('categories')
        ->and($context->sectionUrl)->toBe('')
        ->and($context->rootPath)->toBe('')
        ->and($context->imageId)->toBeNull()
        ->and($context->imageFile)->toBeNull()
        ->and($context->items)->toBe([])
        ->and($context->start)->toBe(0)
        ->and($context->startcat)->toBe(0)
        ->and($context->nbImagePage)->toBe(0)
        ->and($context->flat)->toBeFalse()
        ->and($context->isHomepage)->toBeFalse()
        ->and($context->superOrderBy)->toBeFalse()
        ->and($context->category)->toBeNull()
        ->and($context->combinedCategories)->toBeNull()
        ->and($context->tags)->toBe([])
        ->and($context->tagIds)->toBe([])
        ->and($context->list)->toBe([])
        ->and($context->search)->toBeNull()
        ->and($context->searchDetails)->toBe([])
        ->and($context->qsearchDetails)->toBe([])
        ->and($context->chronologyDate)->toBe([])
        ->and($context->chronologyField)->toBeNull()
        ->and($context->chronologyView)->toBeNull()
        ->and($context->chronologyStyle)->toBeNull()
        ->and($context->title)->toBe('')
        ->and($context->comment)->toBe('')
        ->and($context->sectionTitle)->toBe('');
});

test('constructor assigns every property as given', function (): void {
    $context = new SectionContext(
        section: 'tags',
        sectionUrl: '/tags/1-nature',
        rootPath: '../',
        imageId: null,
        imageFile: null,
        items: [10, 11, 12],
        start: 20,
        startcat: 0,
        nbImagePage: 15,
        flat: false,
        isHomepage: false,
        superOrderBy: true,
        category: null,
        combinedCategories: null,
        tags: [['id' => 1, 'name' => 'nature']],
        tagIds: [1],
        list: [],
        search: null,
        searchDetails: [],
        qsearchDetails: [],
        chronologyDate: [],
        chronologyField: null,
        chronologyView: null,
        chronologyStyle: null,
        title: 'Tags',
        comment: '',
        sectionTitle: '<a href="/">Home</a> / Tags',
    );

    expect($context->section)->toBe('tags')
        ->and($context->sectionUrl)->toBe('/tags/1-nature')
        ->and($context->rootPath)->toBe('../')
        ->and($context->items)->toBe([10, 11, 12])
        ->and($context->start)->toBe(20)
        ->and($context->superOrderBy)->toBeTrue()
        ->and($context->tags)->toBe([['id' => 1, 'name' => 'nature']])
        ->and($context->tagIds)->toBe([1])
        ->and($context->title)->toBe('Tags')
        ->and($context->sectionTitle)->toBe('<a href="/">Home</a> / Tags');
});

test('constructor accepts a picture page image id and file slug', function (): void {
    $context = new SectionContext(
        section: 'categories',
        imageId: '42',
        imageFile: 'my-photo',
    );

    expect($context->imageId)->toBe('42')
        ->and($context->imageFile)->toBe('my-photo');
});

test('constructor accepts a category and combined-categories shape', function (): void {
    $context = new SectionContext(
        section: 'categories',
        category: ['id' => 12, 'name' => 'Holidays'],
        combinedCategories: [['id' => 13, 'name' => 'Summer']],
    );

    expect($context->category)->toBe(['id' => 12, 'name' => 'Holidays'])
        ->and($context->combinedCategories)->toBe([['id' => 13, 'name' => 'Summer']]);
});

test('withItems returns a new instance with only the item list replaced', function (): void {
    $context = new SectionContext(
        section: 'best_rated',
        items: [1, 2, 3],
        start: 10,
        title: 'Best rated',
    );

    $updated = $context->withItems([1, 2, 3, 42]);

    expect($updated)->not->toBe($context)
        ->and($updated->items)->toBe([1, 2, 3, 42])
        ->and($context->items)->toBe([1, 2, 3])
        ->and($updated->section)->toBe('best_rated')
        ->and($updated->start)->toBe(10)
        ->and($updated->title)->toBe('Best rated');
});

test('toUrlParams omits every field left at its default value', function (): void {
    $context = new SectionContext();

    expect($context->toUrlParams())->toBe(['section' => 'categories']);
});

test('toUrlParams includes only the fields that differ from their default', function (): void {
    $context = new SectionContext(
        section: 'categories',
        sectionUrl: '/category/12-holidays',
        rootPath: '../',
        imageId: '42',
        imageFile: 'my-photo',
        items: [1, 2],
        start: 20,
        startcat: 5,
        nbImagePage: 15,
        flat: true,
        isHomepage: true,
        superOrderBy: true,
        category: ['id' => 12, 'name' => 'Holidays'],
        combinedCategories: [['id' => 13, 'name' => 'Summer']],
        tags: [['id' => 1, 'name' => 'nature']],
        tagIds: [1],
        list: [7, 8],
        search: 'psk-20260101-abcdefghij',
        searchDetails: ['q' => 'sunset'],
        qsearchDetails: ['q' => 'sunset'],
        chronologyDate: [2026],
        chronologyField: 'created',
        chronologyView: 'calendar',
        chronologyStyle: 'monthly',
        title: 'Holidays',
        comment: 'A comment',
        sectionTitle: '<a href="/">Home</a> / Holidays',
    );

    expect($context->toUrlParams())->toBe([
        'section' => 'categories',
        'section_url' => '/category/12-holidays',
        'root_path' => '../',
        'items' => [1, 2],
        'start' => 20,
        'startcat' => 5,
        'nb_image_page' => 15,
        'flat' => true,
        'is_homepage' => true,
        'super_order_by' => true,
        'image_id' => '42',
        'image_file' => 'my-photo',
        'category' => ['id' => 12, 'name' => 'Holidays'],
        'combined_categories' => [['id' => 13, 'name' => 'Summer']],
        'tags' => [['id' => 1, 'name' => 'nature']],
        'tag_ids' => [1],
        'list' => [7, 8],
        'search' => 'psk-20260101-abcdefghij',
        'search_details' => ['q' => 'sunset'],
        'qsearch_details' => ['q' => 'sunset'],
        'chronology_date' => [2026],
        'chronology_field' => 'created',
        'chronology_view' => 'calendar',
        'chronology_style' => 'monthly',
        'title' => 'Holidays',
        'comment' => 'A comment',
        'section_title' => '<a href="/">Home</a> / Holidays',
    ]);
});
