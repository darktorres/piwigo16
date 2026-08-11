<?php

declare(strict_types=1);

use Piwigo\Bootstrap\Projection\RedirectHtmlPageContext;

test('toArray flattens the redirect message', function (): void {
    expect(new RedirectHtmlPageContext(redirectMsg: 'Redirection...')->toArray())
        ->toBe([
            'REDIRECT_MSG' => 'Redirection...',
        ]);
});
