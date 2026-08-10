<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;

/**
 * `categories`/`images`/`tags`' own `FULLTEXT` indexes use `WITH PARSER
 * ngram` (`install/piwigo_structure-mysql.sql`) instead of MySQL's default
 * whitespace-tokenizing parser -- the default parser can't match CJK text
 * at all (no word-separating whitespace to tokenize on).
 *
 * `ngram_token_size` is 2, and MySQL's own default INNODB stopword list
 * includes short, common fragments like `at`/`in`/`on` -- confirmed live
 * (not assumed) that if *any* 2-character fragment of a word matches a
 * stopword, MySQL drops every fragment of that *whole* word from the
 * index, not just the matching fragment (e.g. "cat" contains "at",
 * becoming entirely unsearchable), hitting any word containing at/in/on/
 * etc. anywhere in it -- not a rare edge case. Also confirmed live that
 * MySQL bakes a FULLTEXT index's effective stopword-filtering behavior in
 * at CREATE TABLE time, for the lifetime of that index -- a later
 * per-connection session setting at INSERT time has zero effect on an
 * already-existing index. The real fix is `install/
 * piwigo_structure-mysql.sql`'s own `SET SESSION innodb_ft_enable_stopword
 * = 0;` line, which runs once, before the `CREATE TABLE` statements below
 * -- every future write through the ordinary, unmodified repository
 * write paths (`BatchWriter`/`TagRepository::insert()`) then indexes
 * correctly regardless of that connection's own stopword setting.
 */
final class FulltextNgramSearchTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CategoryRepository $categoryRepo;

    private TagRepository $tagRepo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        // This whole class exercises MySQL/InnoDB's own `WITH PARSER
        // ngram` FULLTEXT mechanism directly (raw MATCH()/AGAINST()
        // queries) -- a MySQL-specific tokenizer/stopword-index-creation-
        // time behavior with no Postgres equivalent at all, not a
        // portability gap. PostgreSQL's own FULLTEXT parity
        // (tsvector/to_tsquery via the `simple` dictionary) is a genuinely
        // different mechanism, covered separately by
        // SearchFulltextPortabilityTest.
        if ($this->dbDriver === 'pgsql') {
            self::markTestSkipped('This class exercises MySQL/InnoDB\'s own ngram FULLTEXT parser directly -- no Postgres equivalent; see SearchFulltextPortabilityTest for the Postgres tsquery/tsvector coverage instead.');
        }

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfigTestFactory::get()->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $em = EntityManagerFactory::build($this->conn);
        $this->categoryRepo = new CategoryRepository($em, CurrentConfigTestFactory::get());
        $this->tagRepo = $em->getRepository(TagEntity::class);
    }

    public function test_ngram_parser_finds_cjk_substrings_the_default_parser_cannot(): void
    {
        // Chinese "beautiful landscape photo" -- no word-separating
        // whitespace, unmatchable by MySQL's default whitespace-tokenizing
        // parser at all.
        $catId = $this->insertCategory('美丽的风景照片');

        $hits = $this->fetchCount(
            'SELECT COUNT(*) FROM ' . 'categories' . " WHERE id = ? AND MATCH(name, comment) AGAINST('风景' IN BOOLEAN MODE)",
            [$catId]
        );

        self::assertSame(1, $hits, 'a CJK substring must be findable via ngram tokenization');
    }

    public function test_ngram_parser_finds_a_word_containing_a_stopword_fragment(): void
    {
        // "cat" contains "at" (a default INNODB stopword) -- a word
        // containing a stopword fragment must still be findable through
        // the real category write path (BatchWriter::singleInsert()),
        // unmodified for this test.
        $catId = $this->insertCategory('My Cats and Vacation Photos');

        $hits = $this->fetchCount(
            'SELECT COUNT(*) FROM ' . 'categories' . " WHERE id = ? AND MATCH(name, comment) AGAINST('cat' IN BOOLEAN MODE)",
            [$catId]
        );

        self::assertSame(1, $hits, 'a word containing a stopword fragment ("at" inside "Cats") must still be findable');
    }

    public function test_ngram_parser_finds_a_tag_containing_a_stopword_fragment(): void
    {
        // Real tag write path (TagRepository::insert(), a direct
        // persist()+flush(), not BatchWriter) -- a separate real write
        // path from the category test above.
        $tag = 'vacation-' . bin2hex(random_bytes(4));
        $tagId = $this->tagRepo->insert($tag, $tag);

        $hits = $this->fetchCount(
            'SELECT COUNT(*) FROM ' . 'tags' . " WHERE id = ? AND MATCH(name) AGAINST('at' IN BOOLEAN MODE)",
            [$tagId->value]
        );

        self::assertSame(1, $hits, 'a tag name containing a stopword fragment ("at" inside "vacation") must still be findable');
    }

    public function test_ngram_parser_still_matches_an_ordinary_word_without_any_stopword_fragment(): void
    {
        // Non-regression check: an ordinary word with no stopword-fragment
        // interaction at all must keep matching.
        $catId = $this->insertCategory('Mountain Landscape');

        $hits = $this->fetchCount(
            'SELECT COUNT(*) FROM ' . 'categories' . " WHERE id = ? AND MATCH(name, comment) AGAINST('mountain' IN BOOLEAN MODE)",
            [$catId]
        );

        self::assertSame(1, $hits);
    }

    /**
     * @param array<int<0, max>|string, mixed> $params
     */
    private function fetchCount(string $sql, array $params): int
    {
        $value = $this->conn->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function insertCategory(string $name): int|string
    {
        return $this->categoryRepo->insertCategory([
            'name' => $name,
            'rank' => 0,
            'global_rank' => 0,
            'lastmodified' => Env::now()->format('Y-m-d H:i:s'),
            'commentable' => true,
            'visible' => true,
            'status' => 'public',
        ]);
    }
}
