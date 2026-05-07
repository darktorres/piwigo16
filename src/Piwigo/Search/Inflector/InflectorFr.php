<?php

declare(strict_types=1);

namespace Piwigo\Search\Inflector;

final class InflectorFr implements InflectorInterface
{
    /** @var array<string,string> */
    private array $exceptions;
    /** @var array<string,string> */
    private readonly array $pluralizers;
    /** @var array<string,string> */
    private readonly array $singularizers;

    public function __construct()
    {
        $tmp = 	['monsieur' => 'messieurs',
          'madame' => 'mesdames',
          'mademoiselle' => 'mesdemoiselles',
        ];

        $this->exceptions = $tmp;
        foreach ($tmp as $k => $v) {
            $this->exceptions[$v] = $k;
        }

        $this->pluralizers = array_reverse([ '/$/' => 's',
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

    /** @return string[] */
    public function getVariants(string $word): array
    {
        $res = [];

        $word = strtolower((string) $word);

        $rc = $this->exceptions[$word] ?? null;
        if ($rc !== null) {
            if (!empty($rc)) {
                $res[] = $rc;
            }
            return $res;
        }

        foreach ($this->pluralizers as $rule => $replacement) {
            $rc = preg_replace($rule, $replacement, $word, -1, $count);
            if ($count) {
                if ($rc !== null) {
                    $res[] = $rc;
                }
                break;
            }
        }

        foreach ($this->singularizers as $rule => $replacement) {
            $rc = preg_replace($rule, $replacement, $word, -1, $count);
            if ($count) {
                if ($rc !== null) {
                    $res[] = $rc;
                }
                break;
            }
        }

        return $res;
    }
}
