<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\TagsPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new TagsPageContext(
        formAction: '/admin.php?page=tags',
        pwgToken: 'token123',
        orphanTagNamesArray: '[]',
        warningTags: '',
        messageTags: 'Orphan tags deleted',
        firstTags: [[
            'name' => 'nature',
            'id' => 1,
        ]],
        data: [[
            'name' => 'nature',
            'id' => 1,
        ]],
        total: 1,
        perPage: 100,
        adminPageTitle: 'Tags',
    );

    expect($context->toArray())
        ->toBe([
            'F_ACTION' => '/admin.php?page=tags',
            'PWG_TOKEN' => 'token123',
            'orphan_tag_names_array' => '[]',
            'warning_tags' => '',
            'message_tags' => 'Orphan tags deleted',
            'first_tags' => [[
                'name' => 'nature',
                'id' => 1,
            ]],
            'data' => [[
                'name' => 'nature',
                'id' => 1,
            ]],
            'total' => 1,
            'per_page' => 100,
            'ADMIN_PAGE_TITLE' => 'Tags',
        ]);
});
