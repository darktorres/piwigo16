<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Counts real DBAL statement executions, for use with
 * `Doctrine\DBAL\Logging\Middleware` wrapping a test's own connection --
 * `Doctrine\DBAL\Logging\Statement::execute()` is the one real,
 * per-round-trip signal (its own fixed message template, logged exactly
 * once per prepared-statement execution), not `Connection::query()`/
 * `exec()`'s own differently-worded messages for the parameterless path
 * (`Db\BatchWriter::massInsert()`/`massUpdate()` always pass real
 * `$params`, so they never take that path).
 *
 * Lets a test assert a batched write issued `ceil(N / chunk_size)` real
 * round trips, not N, so a future accidental revert of
 * `Db\BatchWriter::massInsert()`/`massUpdate()` to a per-row loop fails a
 * test immediately instead of only showing up as a slow admin sync.
 */
final class StatementCountingLogger extends AbstractLogger
{
    private const string EXECUTE_MESSAGE = 'Executing statement: {sql} (parameters: {params}, types: {types})';

    public int $executedStatementCount = 0;

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((string) $message === self::EXECUTE_MESSAGE) {
            $this->executedStatementCount++;
        }
    }
}
