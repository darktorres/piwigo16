<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}'s
 * own `case 'send':` display branch. `$authKeyDuration` is genuinely
 * optional -- the original code only ever assigns that template key
 * under its own runtime condition, omitted here (not present as a null
 * value) to match that exact original behavior.
 */
final readonly class NotificationByMailSendPageContext implements TemplatePageContext
{
    /**
     * @param list<array{ID: string, CHECKED: string, USERNAME: string, EMAIL: string, LAST_SEND: ?string}> $users
     */
    public function __construct(
        public array $users,
        public string $customizeMailContent,
        public ?string $authKeyDuration,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'send' => [
                'users' => $this->users,
                'CUSTOMIZE_MAIL_CONTENT' => $this->customizeMailContent,
            ],
        ];

        if ($this->authKeyDuration !== null) {
            $result['auth_key_duration'] = $this->authKeyDuration;
        }

        return $result;
    }
}
