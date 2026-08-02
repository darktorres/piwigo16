<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search\Inflector;

final class Inflector_fr implements InflectorInterface
{
    /**
     * @var array<string, string>
     */
    private array $exceptions;

    /**
     * @var array<non-empty-string, string>
     */
    private readonly array $pluralizers;

    /**
     * @var array<non-empty-string, string>
     */
    private readonly array $singularizers;

    public function __construct()
    {
        $tmp = [
            'monsieur' => 'messieurs',
            'madame' => 'mesdames',
            'mademoiselle' => 'mesdemoiselles',
        ];

        $this->exceptions = $tmp;
        foreach ($tmp as $k => $v) {
            $this->exceptions[$v] = $k;
        }

        $this->pluralizers = array_reverse([
            '/$/' => 's',
            '/(bijou|caillou|chou|genou|hibou|joujou|pou|au|eu|eau)$/' => '\1x',
            '/(bleu|émeu|landau|lieu|pneu|sarrau)$/' => '\1s',
            '/al$/' => 'aux',
            '/ail$/' => 'ails',
            '/(b|cor|ém|gemm|soupir|trav|vant|vitr)ail$/' => '\1aux',
            '/(s|x|z)$/' => '\1',
        ]);

        $this->singularizers = array_reverse([
            '/s$/' => '',
            '/(bijou|caillou|chou|genou|hibou|joujou|pou|au|eu|eau)x$/' => '\1',
            '/(journ|chev)aux$/' => '\1al',
            '/ails$/' => 'ail',
            '/(b|cor|ém|gemm|soupir|trav|vant|vitr)aux$/' => '\1ail',
        ]);
    }

    /**
     * @return array<int, string>
     */
    #[\Override]
    public function get_variants(string $word): array
    {
        $res = [];

        $word = strtolower($word);

        $rc = $this->exceptions[$word] ?? null;
        if (isset($rc)) {
            if ($rc !== '') {
                $res[] = $rc;
            }
            return $res;
        }

        foreach ($this->pluralizers as $rule => $replacement) {
            $rc = preg_replace($rule, $replacement, $word, -1, $count);
            if ((bool) $count) {
                // $pluralizers is this class's own fixed, valid regex
                // literal array -- preg_replace() only returns null on a
                // compile error, which is unreachable here.
                assert($rc !== null);
                $res[] = $rc;
                break;
            }
        }

        foreach ($this->singularizers as $rule => $replacement) {
            $rc = preg_replace($rule, $replacement, $word, -1, $count);
            if ((bool) $count) {
                // same invariant as $pluralizers above.
                assert($rc !== null);
                $res[] = $rc;
                break;
            }
        }

        return $res;
    }
}
