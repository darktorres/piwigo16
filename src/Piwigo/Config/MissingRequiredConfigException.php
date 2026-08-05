<?php

declare(strict_types=1);

namespace Piwigo\Config;

use RuntimeException;

/**
 * Thrown by ConfigLoader::validateRequired() when a SCHEMA key marked
 * 'required' => true has no value after defaults + env overrides + (once
 * P14 lands) DB merge.
 */
final class MissingRequiredConfigException extends RuntimeException {}
