<?php

declare(strict_types=1);

use Piwigo\Ws\CategoryTreeBuilder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\XmlAttributeLists;

/**
 * Piwigo\Ws\CategoryTreeBuilder -- split out of the former WsHelper
 * god-class (P25 Stage 1 step 6).
 */
test('categoriesFlatlistToTree attaches a child under its real parent, root-level categories go straight into the tree', function (): void {
    $builder = new CategoryTreeBuilder(new XmlAttributeLists());
    $categories = [
        [
            'id' => 1,
            'name' => 'Root',
        ],
        [
            'id' => 2,
            'name' => 'Child',
            'id_uppercat' => 1,
        ],
    ];

    $tree = $builder->categoriesFlatlistToTree($categories);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['name'])->toBe('Root')
        ->and($tree[0]['sub_categories'])->toBeInstanceOf(NamedArray::class);
    $subCategories = $tree[0]['sub_categories'];
    if ($subCategories instanceof NamedArray) {
        expect($subCategories->content)->toHaveCount(1);
        $child = $subCategories->content[0];
        if (is_array($child)) {
            expect($child['name'])->toBe('Child');
        }
    }
});

test('categoriesFlatlistToTree attaches a node at top level when its id_uppercat is absent from the flat list', function (): void {
    $builder = new CategoryTreeBuilder(new XmlAttributeLists());
    $categories = [
        [
            'id' => 2,
            'name' => 'Orphan',
            // id_uppercat 1 was filtered out of this flat list upstream
            // (e.g. permission scope) -- never appears as its own row.
            'id_uppercat' => 1,
        ],
    ];

    $tree = $builder->categoriesFlatlistToTree($categories);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['name'])->toBe('Orphan');
});

test('categoriesFlatlistToTree skips a row with a non-scalar id', function (): void {
    $builder = new CategoryTreeBuilder(new XmlAttributeLists());
    $categories = [
        [
            'id' => 1,
            'name' => 'Root',
        ],
        [
            'id' => null,
            'name' => 'Malformed',
        ],
    ];

    $tree = $builder->categoriesFlatlistToTree($categories);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['name'])->toBe('Root');
});
