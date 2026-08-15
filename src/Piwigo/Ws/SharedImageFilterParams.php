<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Core\WsParamType;

/**
 * The shared images-table range-filter block merged into several WS
 * methods' own params -- moved out of the old WsDefaultMethods god-method
 * (P25 Stage 1) since it's used by registrars in 4 different domains
 * (Categories, Core, Images, Tags), not just one.
 */
final class SharedImageFilterParams
{
    /**
     * @return list<ParamDefinition>
     */
    public static function get(): array
    {
        return [
            ParamDefinition::optional('f_min_rate', null, WsParamType::FLOAT),
            ParamDefinition::optional('f_max_rate', null, WsParamType::FLOAT),
            ParamDefinition::optional('f_min_hit', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_hit', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_min_ratio', null, WsParamType::FLOAT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_ratio', null, WsParamType::FLOAT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_max_level', null, WsParamType::INT | WsParamType::POSITIVE),
            ParamDefinition::optional('f_min_date_available'),
            ParamDefinition::optional('f_max_date_available'),
            ParamDefinition::optional('f_min_date_created'),
            ParamDefinition::optional('f_max_date_created'),
        ];
    }
}
