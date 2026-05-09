<?php

declare(strict_types=1);

/**
 * Latte engine bootstrap for efabrica/phpstan-latte. Returns the same
 * configured Engine that LatteEngine boots in production (PiwigoExtension
 * with translate / translate_dec filters, StrictTypes feature) so the
 * static analyser knows which filters/functions are valid on Piwigo
 * `.latte` templates.
 *
 * Wired via parameters.latte.engineBootstrap in phpstan.neon.
 */

require __DIR__ . '/../vendor/autoload.php';

use Latte\Engine;
use Latte\Feature;
use Piwigo\Template\Latte\PiwigoExtension;

$engine = new Engine();
$engine->setFeature(Feature::StrictTypes);
$engine->addExtension(new PiwigoExtension());

return $engine;
