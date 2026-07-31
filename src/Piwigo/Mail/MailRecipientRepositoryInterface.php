<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Piwigo\Mail\Projection\MailRecipient;

/**
 * Seam MailService::__construct()'s $mailRecipientRepo parameter type-hints
 * against, so tests can substitute a fake implementation without extending
 * the final MailRecipientRepository directly.
 */
interface MailRecipientRepositoryInterface
{
    /**
     * @param  list<string>  $userStatuses
     * @return list<MailRecipient>
     */
    public function findAdminsAndWebmasters(
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        array $userStatuses,
        ?int $groupId,
        ?int $excludeUserId
    ): array;

    /**
     * @return list<string>
     */
    public function findDistinctLanguagesInGroup(
        string $idColumn,
        string $emailColumn,
        int $groupId,
        ?string $languageFilter
    ): array;

    /**
     * @return list<MailRecipient>
     */
    public function findByGroupAndLanguage(
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        int $groupId,
        string $language
    ): array;
}
