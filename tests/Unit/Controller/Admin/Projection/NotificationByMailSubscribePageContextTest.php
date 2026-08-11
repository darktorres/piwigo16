<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\NotificationByMailSubscribePageContext;

test('toArray flattens every property, including the literal subscribe marker', function (): void {
    $context = new NotificationByMailSubscribePageContext(
        lCatOptionsTrue: 'Subscribed',
        lCatOptionsFalse: 'Unsubscribed',
        categoryOptionTrue: [
            'abc' => 'jane[jane@example.test]',
        ],
        categoryOptionTrueSelected: ['abc'],
        categoryOptionFalse: [
            'def' => 'john[john@example.test]',
        ],
        categoryOptionFalseSelected: [],
    );

    expect($context->toArray())
        ->toBe([
            'subscribe' => true,
            'L_CAT_OPTIONS_TRUE' => 'Subscribed',
            'L_CAT_OPTIONS_FALSE' => 'Unsubscribed',
            'category_option_true' => [
                'abc' => 'jane[jane@example.test]',
            ],
            'category_option_true_selected' => ['abc'],
            'category_option_false' => [
                'def' => 'john[john@example.test]',
            ],
            'category_option_false_selected' => [],
        ]);
});
