<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

function xmlrpc_encode(mixed $data): string
{
    switch (gettype($data)) {
        case 'boolean':
            return '<boolean>' . ($data ? '1' : '0') . '</boolean>';
        case 'integer':
            return '<int>' . $data . '</int>';
        case 'double':
            return '<double>' . $data . '</double>';
        case 'string':
            return '<string>' . htmlspecialchars($data) . '</string>';
        case 'object':
        case 'array':
            $data = is_object($data) ? get_object_vars($data) : $data;
            $is_array = range(0, count($data) - 1) === array_keys($data);
            if ($is_array) {
                $return = '<array><data>' . "\n";
                foreach ($data as $item) {
                    $return .= '  <value>' . xmlrpc_encode($item) . "</value>\n";
                }
                $return .= '</data></array>';
            } else {
                $return = '<struct>' . "\n";
                foreach ($data as $name => $value) {
                    $name = htmlspecialchars((string) $name);
                    $return .= "  <member><name>{$name}</name><value>";
                    $return .= xmlrpc_encode($value) . "</value></member>\n";
                }
                $return .= '</struct>';
            }
            return $return;
    }

    return '';
}
