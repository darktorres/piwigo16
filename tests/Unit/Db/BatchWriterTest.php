<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Db\BatchWriter;

/**
 * BatchWriter's real happy paths (singleInsert()/massInsert()/singleUpdate()/
 * the rollback+rethrow paths) already have full, real-DB coverage in
 * tests/Integration/BatchWriterTest.php -- that file's own docblock explains
 * why (transactions, real UNIQUE constraint violations, a scratch table).
 *
 * This file closes a narrower, structural gap the mutation sweep found in
 * updateRow()'s own per-field placeholder-name construction (line 196's
 * `'set' . $i++`): a DBAL connection is genuinely lazy (confirmed by
 * DbConnectionTest.php's own docblock) up until a query actually executes,
 * and Doctrine DBAL itself supports a real, working in-memory pdo_sqlite
 * connection with no socket/network involved at all -- fast and safe enough
 * for the Unit tier despite touching a real (if ephemeral) database, closing
 * a gap the mocked-away happy-path call sites elsewhere in the app never
 * reach with more than one SET field at a time.
 */
function batchWriterTestMemoryConnection(): Connection
{
    $conn = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    $conn->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT, b TEXT)');
    $conn->executeStatement('INSERT INTO t (id, a, b) VALUES (1, \'x\', \'y\')');

    return $conn;
}

test('singleUpdate binds each SET field to its own distinct value, not just the last one, when updating multiple columns at once', function (): void {
    // Kills line 196's ConcatRemoveRight (dropping the `. $i++` suffix
    // entirely, so every SET field shares the literal placeholder name
    // 'set' -- confirmed live this makes every column silently receive
    // the LAST field's value instead of its own) and
    // PostIncrementToPostDecrement (`$i--` instead of `$i++`, walking
    // the counter negative and producing invalid placeholder names like
    // 'set-1' -- confirmed live this throws a genuine DBAL
    // MissingNamedParameter exception on the second field, since a
    // negative-suffixed name doesn't round-trip through DBAL's own
    // named-parameter binding the way a real generated one does).
    $conn = batchWriterTestMemoryConnection();
    $writer = new BatchWriter($conn);

    $writer->singleUpdate('t', [
        'a' => 'newA',
        'b' => 'newB',
    ], [
        'id' => 1,
    ]);

    expect($conn->fetchAssociative('SELECT a, b FROM t WHERE id = 1'))
        ->toBe([
            'a' => 'newA',
            'b' => 'newB',
        ]);
});

test('singleUpdate treats an explicitly empty string field as NULL, not as a bound empty-string value', function (): void {
    // Kills line 187's EmptyStringToNotEmpty: real code's $value === ''
    // check routes an empty string to the "set column = NULL" SQL
    // branch (not a bound parameter at all) -- confirmed live via
    // var_dump that the stored value is genuinely NULL, not an empty
    // string. The mutant's placeholder-text comparison never matches a
    // real '', so it wrongly falls through to binding '' as a real
    // parameter value instead.
    $conn = batchWriterTestMemoryConnection();
    $writer = new BatchWriter($conn);

    $writer->singleUpdate('t', [
        'a' => '',
    ], [
        'id' => 1,
    ]);

    $row = $conn->fetchAssociative('SELECT a FROM t WHERE id = 1');
    expect($row)
        ->not->toBeFalse();
    assert(is_array($row));
    expect($row['a'])->toBeNull();
});

test('singleUpdate continues past an empty, non-skipped field to still process a later non-empty field, instead of aborting the whole loop', function (): void {
    // Kills line 193's ContinueToBreak (`break;` instead of `continue;`,
    // right after the "set column = NULL" branch that runs when a field
    // is empty and SKIP_EMPTY is NOT set). Confirmed live: with an empty
    // 'a' field ahead of a non-empty 'b' field and no SKIP_EMPTY flag,
    // real code sets a = NULL and still processes 'b' normally (b =
    // 'newB'); the mutant's `break` exits the foreach entirely after
    // 'a', so 'b' is never touched and keeps its original value.
    $conn = batchWriterTestMemoryConnection();
    $writer = new BatchWriter($conn);

    $writer->singleUpdate('t', [
        'a' => '',
        'b' => 'newB',
    ], [
        'id' => 1,
    ]);

    $row = $conn->fetchAssociative('SELECT a, b FROM t WHERE id = 1');
    expect($row)
        ->not->toBeFalse();
    assert(is_array($row));
    expect($row['a'])->toBeNull()
        ->and($row['b'])->toBe('newB');
});

test('singleUpdate routes a non-scalar WHERE value to an IS NULL condition, not a bound parameter', function (): void {
    // Kills line 209's BooleanAndToBooleanOr (`isset($value) ||
    // is_scalar($value)` instead of `&&`). For a non-null, non-scalar
    // WHERE value (an array survives SqlDialect::booleanToInt()
    // unchanged), real code's `&&` is false (is_scalar() rejects it),
    // routing to the "column IS NULL" branch. The mutant's `||` is true
    // (isset() alone is enough), routing instead to a bound parameter --
    // which PDO can only bind by silently coercing the array to the
    // string 'Array'. Confirmed live with two decoy rows (one with a
    // genuinely NULL `code`, one with the literal string 'Array' as
    // `code`): real code updates the NULL row; the mutant updates the
    // 'Array'-string row instead -- a different row entirely, not just
    // a different SQL string.
    $conn = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    $conn->executeStatement('CREATE TABLE t3 (id INTEGER PRIMARY KEY, code TEXT, note TEXT)');
    $conn->executeStatement('INSERT INTO t3 (id, code, note) VALUES (1, NULL, \'row1-orig\')');
    $conn->executeStatement('INSERT INTO t3 (id, code, note) VALUES (2, \'Array\', \'row2-orig\')');

    $writer = new BatchWriter($conn);
    $writer->singleUpdate('t3', [
        'note' => 'updated',
    ], [
        'code' => [9, 9],
    ]);

    expect($conn->fetchOne('SELECT note FROM t3 WHERE id = 1'))
        ->toBe('updated');
    expect($conn->fetchOne('SELECT note FROM t3 WHERE id = 2'))
        ->toBe('row2-orig');
});

test('singleUpdate binds each WHERE condition to its own distinct value, not colliding across multiple conditions', function (): void {
    // Kills line 210's ConcatRemoveRight (every WHERE placeholder
    // sharing the literal name 'where' -- confirmed live this makes
    // every condition bind to the LAST condition's own value, so a
    // multi-column WHERE clause that should match a real row instead
    // matches none, leaving the row unchanged) and
    // PostIncrementToPostDecrement (`$j--` instead of `$j++`, producing
    // invalid negative-suffixed placeholder names -- confirmed live
    // this throws the same genuine DBAL MissingNamedParameter exception
    // as line 196's own SET-loop equivalent).
    $conn = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);
    $conn->executeStatement('CREATE TABLE t2 (id INTEGER PRIMARY KEY, a TEXT, b TEXT, note TEXT)');
    $conn->executeStatement('INSERT INTO t2 (id, a, b, note) VALUES (1, \'x\', \'y\', \'orig\')');

    $writer = new BatchWriter($conn);
    $writer->singleUpdate('t2', [
        'note' => 'updated',
    ], [
        'a' => 'x',
        'b' => 'y',
        'id' => 1,
    ]);

    expect($conn->fetchOne('SELECT note FROM t2 WHERE id = 1'))
        ->toBe('updated');
});

/**
 * Confirmed-equivalent: line 187's BooleanOrToBooleanAnd (`! isset
 * ($value) && $value === ''` instead of `||`). `isset($value)` is
 * false ONLY for a genuinely null $value, and null is also never
 * scalar -- so `! is_scalar($value)`, the chain's own THIRD condition,
 * already independently catches every null value on its own, the same
 * way it does for arrays/objects. Mutating just the first `||`/`&&`
 * junction changes nothing about the final result for a null $value;
 * confirmed live with an explicit null field.
 *
 * Also confirmed-equivalent: line 184's IncrementInteger (`$i = 1;`
 * instead of `= 0;`, the SET-loop's own counter) -- same reasoning as
 * line 206's WHERE-loop sibling below: SET placeholders only need to
 * stay unique among themselves (they do, since $i still strictly
 * increases) and distinct from WHERE's separately-prefixed
 * placeholders (they still are). Confirmed live: the full suite in this
 * file passes identically with the starting value changed.
 *
 * Also confirmed-equivalent: line 206's IncrementInteger (`$j = 1;`
 * instead of `= 0;`) and line 210's ConcatRemoveLeft/ConcatSwitchSides
 * (dropping the 'where' prefix, or swapping it to the end). WHERE
 * placeholders only need to be unique from each OTHER and from the
 * separately-prefixed SET placeholders ('setN') -- neither the
 * starting offset nor the exact prefix text can ever cause a real
 * collision, since SET always uses the literal 'set' prefix regardless
 * of what WHERE does. Confirmed live with a genuinely multi-condition
 * WHERE clause (3 conditions, forcing the counter to actually
 * advance): all 3 mutations produce byte-identical query results to
 * real code.
 *
 * Also confirmed-equivalent: line 189's RemoveBooleanCast (dropping the
 * `(bool)` around `$flags & self::SKIP_EMPTY` inside the `if`). An `if`
 * condition already coerces any int to bool on its own -- a nonzero
 * bitwise-AND result is truthy either way, so the explicit cast changes
 * nothing about which branch runs. Confirmed live: the full suite in
 * this file passes identically with the cast removed.
 *
 * Also confirmed-equivalent: line 196's ConcatSwitchSides (`$i++ .
 * 'set'` instead of `'set' . $i++`, producing '0set'/'1set'/... instead
 * of 'set0'/'set1'/...). The same reasoning as line 210's own
 * ConcatSwitchSides applies: SET placeholders only need to stay unique
 * among themselves (they do, since $i strictly increases) and distinct
 * from WHERE's separately-prefixed placeholders (they still are, since
 * 'where' never starts with a digit). Confirmed live: the full suite in
 * this file passes identically with the concat sides swapped.
 */
