<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Engine-agnostic contract for rendering Piwigo page templates.
 *
 * Phase F dropped `smarty/smarty`; {@see LatteEngine} is currently the
 * only implementer. The interface remains as the forward-compatible
 * shape for direct callers and any future engine.
 */
interface TemplateEngine
{
    public function assign(string $name, mixed $value): void;

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): string;
}
