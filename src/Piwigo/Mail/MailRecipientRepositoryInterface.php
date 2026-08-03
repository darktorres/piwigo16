<?php

declare(strict_types=1);

namespace Piwigo\Mail;

use Piwigo\Mail\Projection\MailRecipient;

/**
 * Seam MailService::__construct()'s $mailRecipientRepo parameter type-hints
 * against, so tests can substitute a fake implementation without extending
 * the final MailRecipientRepository directly.
 *
 * SQL-modernization audit, Item 14 Sub-phase C4: dropped every
 * `$idColumn`/`$usernameColumn`/`$emailColumn` multi-auth column-name
 * param -- `users` is now mapped ({@see \Piwigo\Users\UserEntity}), always
 * `id`/`username`/`mail_address`.
 */
interface MailRecipientRepositoryInterface
{
    /**
     * @param  list<string>  $userStatuses
     * @return list<MailRecipient>
     */
    public function findAdminsAndWebmasters(
        array $userStatuses,
        ?int $groupId,
        ?int $excludeUserId
    ): array;

    /**
     * @return list<string>
     */
    public function findDistinctLanguagesInGroup(
        int $groupId,
        ?string $languageFilter
    ): array;

    /**
     * @return list<MailRecipient>
     */
    public function findByGroupAndLanguage(
        int $groupId,
        string $language
    ): array;
}
