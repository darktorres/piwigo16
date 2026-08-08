<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\Install\InstallWizard::performInstall()} when
 * `database.inc.php` couldn't be written and the operator must download
 * it manually instead.
 */
final readonly class InstallConfigFailurePageContext implements TemplatePageContext
{
    public function __construct(
        public bool $configCreationFailed,
        public string $configUrl,
        public string $configFileContent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'config_creation_failed' => $this->configCreationFailed,
            'config_url' => $this->configUrl,
            'config_file_content' => $this->configFileContent,
        ];
    }
}
