<?php

declare(strict_types=1);

// phpstan-doctrine's objectManagerLoader -- gives the DQL/QueryBuilder
// result-type inference a real EntityManager to read entity metadata from.
// Building the EntityManager itself is lazy (EntityManagerFactory::build()/
// DbConnection::build()), but Doctrine's DQL parser needs the real database
// platform (MySQL vs PostgreSQL) to translate some constructs, which does
// open a real connection -- same .env.test the test suite itself connects
// with (tests/bootstrap.php's own env-loading, mirrored here).

require __DIR__ . '/../vendor/autoload.php';

$_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
\Piwigo\Core\Env::loadEnvFile(dirname(__DIR__));

return \Piwigo\Db\EntityManagerFactory::build();
