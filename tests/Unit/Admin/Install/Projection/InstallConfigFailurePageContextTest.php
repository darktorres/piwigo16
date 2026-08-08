<?php

declare(strict_types=1);

use Piwigo\Admin\Install\Projection\InstallConfigFailurePageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new InstallConfigFailurePageContext(
        configCreationFailed: true,
        configUrl: 'install.php?dl=abc123',
        configFileContent: "<?php\n\$conf['dblayer'] = 'mysqli';",
    );

    expect($context->toArray())->toBe([
        'config_creation_failed' => true,
        'config_url' => 'install.php?dl=abc123',
        'config_file_content' => "<?php\n\$conf['dblayer'] = 'mysqli';",
    ]);
});
