<?php

declare(strict_types=1);

use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryStatus;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;

function fullCategoryEntity(): CategoryEntity
{
    $entity = new CategoryEntity(
        name: 'Sample Album',
        idUppercat: 1,
        comment: 'a description',
        dir: 'sample_dir',
        rank: 2,
        status: CategoryStatus::Private,
        siteId: 1,
        visible: true,
        representativePictureId: 42,
        uppercats: '1,3',
        commentable: true,
        globalRank: '1.2',
        imageOrder: 'name ASC',
        permalink: Permalink::from('sample-album'),
        lastmodified: SqlDateTime::from('2026-08-01 00:00:00'),
    );
    $entity->id = CategoryId::from(3);

    return $entity;
}

/**
 * @return array<string, mixed>
 */
function fullCategoryRow(): array
{
    return [
        'id' => '3',
        'name' => 'Sample Album',
        'id_uppercat' => '1',
        'comment' => 'a description',
        'dir' => 'sample_dir',
        'rank' => '2',
        'status' => 'private',
        'site_id' => '1',
        'visible' => '1',
        'representative_picture_id' => '42',
        'uppercats' => '1,3',
        'commentable' => '1',
        'global_rank' => '1.2',
        'image_order' => 'name ASC',
        'permalink' => 'sample-album',
        'lastmodified' => '2026-08-01 00:00:00',
    ];
}

test('fromRow narrows a real DB row into typed properties', function (): void {
    $cat = Category::fromRow(fullCategoryRow());

    expect($cat->id)
        ->toEqual(CategoryId::from(3))
        ->and($cat->name)
        ->toBe('Sample Album')
        ->and($cat->idUppercat)
        ->toBe(1)
        ->and($cat->comment)
        ->toBe('a description')
        ->and($cat->dir)
        ->toBe('sample_dir')
        ->and($cat->rank)
        ->toBe(2)
        ->and($cat->status)
        ->toBe('private')
        ->and($cat->siteId)
        ->toBe(1)
        ->and($cat->visible)
        ->toBeTrue()
        ->and($cat->representativePictureId)
        ->toBe(42)
        ->and($cat->uppercats)
        ->toBe('1,3')
        ->and($cat->commentable)
        ->toBeTrue()
        ->and($cat->globalRank)
        ->toBe('1.2')
        ->and($cat->imageOrder)
        ->toBe('name ASC')
        ->and($cat->permalink)
        ->toEqual(Permalink::from('sample-album'))
        ->and($cat->lastmodified)
        ->toBe('2026-08-01 00:00:00');
});

test('fromRow keeps already-hydrated CategoryId/Permalink/CategoryStatus instances as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: real Doctrine array
    // hydration would already have converted id/permalink/status via
    // their own custom Types, not left them as raw scalars.
    $row = fullCategoryRow();
    $row['id'] = CategoryId::from(9);
    $row['permalink'] = Permalink::from('other-slug');
    $row['status'] = CategoryStatus::Public;

    $cat = Category::fromRow($row);

    expect($cat->id)
        ->toEqual(CategoryId::from(9))
        ->and($cat->permalink)
        ->toEqual(Permalink::from('other-slug'))
        ->and($cat->status)
        ->toBe('public');
});

test('fromRow defaults every nullable column to null/default when absent', function (): void {
    $cat = Category::fromRow([
        'id' => 1,
    ]);

    expect($cat->name)
        ->toBe('')
        ->and($cat->idUppercat)
        ->toBeNull()
        ->and($cat->comment)
        ->toBeNull()
        ->and($cat->dir)
        ->toBeNull()
        ->and($cat->rank)
        ->toBeNull()
        ->and($cat->status)
        ->toBe('public')
        ->and($cat->siteId)
        ->toBeNull()
        ->and($cat->visible)
        ->toBeTrue()
        ->and($cat->representativePictureId)
        ->toBeNull()
        ->and($cat->uppercats)
        ->toBe('')
        ->and($cat->commentable)
        ->toBeTrue()
        ->and($cat->globalRank)
        ->toBeNull()
        ->and($cat->imageOrder)
        ->toBeNull()
        ->and($cat->permalink)
        ->toBeNull()
        ->and($cat->lastmodified)
        ->toBe('');
});

test('fromRow throws when id is missing', function (): void {
    expect(fn () => Category::fromRow([]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive category id, got null');
});

test('fromRow throws with the real debug type of a non-null but invalid id', function (): void {
    expect(fn () => Category::fromRow([
        'id' => 'not-a-number',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive category id, got string');
});

test('fromEntity copies every field straight through, keeping the real VOs', function (): void {
    $cat = Category::fromEntity(fullCategoryEntity());

    expect($cat->id)
        ->toEqual(CategoryId::from(3))
        ->and($cat->name)
        ->toBe('Sample Album')
        ->and($cat->status)
        ->toBe('private')
        ->and($cat->permalink)
        ->toEqual(Permalink::from('sample-album'));
});

test('fromEntity throws when the entity has no id yet (not persisted)', function (): void {
    $entity = fullCategoryEntity();
    $entity->id = null;

    expect(fn () => Category::fromEntity($entity))
        ->toThrow(InvalidArgumentException::class, 'Category::fromEntity(): entity has no id (not yet persisted)');
});

test('toArray round-trips every typed property back into its raw DB-shaped key', function (): void {
    $roundTripped = Category::fromRow(fullCategoryRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'id' => 3,
            'name' => 'Sample Album',
            'id_uppercat' => 1,
            'comment' => 'a description',
            'dir' => 'sample_dir',
            'rank' => 2,
            'status' => 'private',
            'site_id' => 1,
            'visible' => true,
            'representative_picture_id' => 42,
            'uppercats' => '1,3',
            'commentable' => true,
            'global_rank' => '1.2',
            'image_order' => 'name ASC',
            'permalink' => 'sample-album',
            'lastmodified' => '2026-08-01 00:00:00',
        ]);
});

test('toArray unwraps a null permalink to null', function (): void {
    $row = fullCategoryRow();
    $row['permalink'] = null;

    expect(Category::fromRow($row)->toArray()['permalink'])->toBeNull();
});
