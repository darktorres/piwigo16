<?php

declare(strict_types=1);

namespace Piwigo\Audit;

/**
 * Audit domain business logic [SEC-57]: records an admin action, permission
 * change, or deletion as a hash-chained, append-only log line, and verifies
 * the chain hasn't been tampered with. Constructor-injects only
 * AuditRepository (plain constructor injection).
 *
 * PII-aware: `record()` stores `$actorId` (a user id), never raw PII. The
 * `$before`/`$after` snapshots are the CALLER's own responsibility to keep
 * PII-minimal -- this service doesn't redact anything itself. P18's own
 * call sites (UserService::registerUser(), GroupService::create()/delete()/
 * addAccess()/removeAccess()) only ever pass identifying-but-not-sensitive
 * fields (e.g. a new user's username, never their password hash).
 *
 * Hash chain: `row_hash` = sha256(prev row's row_hash + this row's own
 * content), so altering any row (content or its own stored hash)
 * invalidates every later row's hash too. `verifyChain()` walks the whole
 * table and recomputes every hash to confirm nothing has been tampered
 * with.
 */
final readonly class AuditService
{
    public function __construct(
        private AuditRepository $repo,
    ) {}

    /**
     * @param array<string, mixed>|null $before null when the action has no
     *   "prior state" (e.g. a creation)
     * @param array<string, mixed>|null $after null when the action leaves
     *   no "new state" (e.g. a deletion)
     * @return int the new audit_log row's id
     */
    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): int {
        $beforeJson = $before !== null ? json_encode($before, \JSON_THROW_ON_ERROR) : null;
        $afterJson = $after !== null ? json_encode($after, \JSON_THROW_ON_ERROR) : null;
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        // Explicit, not left to the column's DEFAULT CURRENT_TIMESTAMP, so
        // this respects the frozen test-mode clock the same way every
        // other P17/P18 service's own Env::now() call sites already do --
        // real behavior outside test mode is unaffected.
        $createdAt = \Piwigo\Core\Env::now()
            ->format('Y-m-d H:i:s');

        $prevHash = $this->repo->findLatestRowHash();
        $rowHash = self::computeHash($prevHash, $actorId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ipAddress, $createdAt);

        return $this->repo->insert($actorId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ipAddress, $createdAt, $prevHash, $rowHash);
    }

    /**
     * Confirms every row's hash matches what it should be given its own
     * content and the previous row's hash, and that every row's own
     * `prev_hash` actually equals its predecessor's `row_hash`. False on
     * the first mismatch found -- tampering with row N (its content or
     * either stored hash) invalidates the chain from N onward.
     */
    public function verifyChain(): bool
    {
        $expectedPrevHash = null;

        foreach ($this->repo->findAllInOrder() as $row) {
            if ($row->prevHash !== $expectedPrevHash) {
                return false;
            }

            $expectedHash = self::computeHash(
                $row->prevHash,
                $row->actorId,
                $row->action,
                $row->entityType,
                $row->entityId,
                $row->beforeJson,
                $row->afterJson,
                $row->ipAddress,
                $row->createdAt,
            );

            if (! hash_equals($expectedHash, $row->rowHash)) {
                return false;
            }

            $expectedPrevHash = $row->rowHash;
        }

        return true;
    }

    private static function computeHash(
        ?string $prevHash,
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $ipAddress,
        string $createdAt,
    ): string {
        $payload = implode('|', [
            $prevHash ?? '',
            $actorId !== null ? (string) $actorId : '',
            $action,
            $entityType,
            $entityId !== null ? (string) $entityId : '',
            self::canonicalJson($beforeJson),
            self::canonicalJson($afterJson),
            $ipAddress ?? '',
            $createdAt,
        ]);

        return hash('sha256', $payload);
    }

    /**
     * MySQL's native JSON column type re-serializes a stored value into
     * its own canonical text form (notably: a space after every `:`) --
     * a value read back via findAllInOrder() is byte-for-byte different
     * from the string record() originally passed to INSERT, even though
     * it's the exact same JSON value. Hashing the raw string as-is would
     * make verifyChain() permanently disagree with record()'s own hash
     * for any row carrying JSON, defeating the whole point of the chain.
     * Decoding then re-encoding through PHP's own json_encode() collapses
     * both forms to the same bytes before they're ever hashed.
     */
    private static function canonicalJson(?string $json): string
    {
        if ($json === null) {
            return '';
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
    }
}
