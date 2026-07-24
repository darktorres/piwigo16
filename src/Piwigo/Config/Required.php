<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Marks a CurrentConfig property that must resolve to a non-empty value --
 * discovered via reflection (ConfigLoader::validateRequired()), replacing
 * the former Config::SCHEMA 'required' => true flag. Config generic-
 * accessor removal, design #4: the flag lives on the property it
 * describes instead of a parallel array that could drift out of sync.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Required {}
