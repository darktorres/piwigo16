<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

$filter_ = new Filter();

$filter_->includeFiles(
    [
    ]
);

$coverage_ = new CodeCoverage(
    (new Selector())->forLineCoverage($filter_),
    $filter_
);
$coverage_->excludeUncoveredFiles();

$coverage_->start($_SERVER['REQUEST_URI']);

function save_coverage(): void
{
    global $coverage_;
    $coverage_->stop();
    (new PhpReport())->process($coverage_, 'C:/Apache24/logs/xdebug/coverage/' . bin2hex(random_bytes(16)) . '.cov');
}

register_shutdown_function('save_coverage');
