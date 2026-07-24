<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Marks a CurrentConfig property that CurrentConfig::dumpForLog() redacts
 * -- discovered via reflection, replacing the former Config::SCHEMA
 * 'sensitive' => true flag. Config generic-accessor removal, design #4:
 * the flag lives on the property it describes instead of a parallel array
 * that could drift out of sync.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Sensitive {}
