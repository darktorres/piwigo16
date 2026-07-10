<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/InflectorInterface.php';

class Inflector_en implements InflectorInterface
{
    /**
     * @var array<string, int|string>
     */
    private array $exceptions;

    /**
     * @var array<string, string>
     */
    private readonly array $pluralizers;

    /**
     * @var array<string, string>
     */
    private readonly array $singularizers;

    /**
     * @var array<string, string>
     */
    private readonly array $er2ing;

    /**
     * @var array<string, string>
     */
    private readonly array $ing2er;

    public function __construct()
    {
        $tmp = [
            'octopus' => 'octopuses',
            'virus' => 'viruses',
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'child' => 'children',
            'move' => 'moves',
            'mouse' => 'mice',
            'ox' => 'oxen',
            'zombie' => 'zombies', // pl->sg exc.
            'serie' => 'series', // pl->sg exc.
            'movie' => 'movies', // pl->sg exc.
        ];

        $this->exceptions = $tmp;
        foreach ($tmp as $k => $v) {
            $this->exceptions[$v] = $k;
        }

        foreach (explode(' ', 'new news advice art coal baggage butter clothing cotton currency deer energy equipment experience fish flour food furniture gas homework impatience information jeans knowledge leather love luggage money oil patience police polish progress research rice series sheep silk soap species sugar talent toothpaste travel vinegar weather wood wool work') as $v) {
            $this->exceptions[$v] = 0;
        }

        $this->pluralizers = array_reverse([
            '/$/' => 's',
            '/s$/' => 's',
            '/^(ax|test)is$/' => '\1es',
            '/(alias|status)$/' => '\1es',
            '/(bu)s$/' => '\1ses',
            '/(buffal|tomat)o$/' => '\1oes',
            '/([ti])um$/' => '\1a',
            '/([ti])a$/' => '\1a',
            '/sis$/' => 'ses',
            '/(?:([^f])fe|([lr])f)$/' => '\1\2ves',
            '/(hive)$/' => '\1s',
            '/([^aeiouy]|qu)y$/' => '\1ies',
            '/(x|ch|ss|sh)$/' => '\1es',
            '/(matr|vert|ind)(?:ix|ex)$/' => '\1ices',
            '/(quiz)$/' => '\1zes',
        ]);

        $this->singularizers = array_reverse([
            '/s$/' => '',
            '/(ss)$/' => '\1',
            '/([ti])a$/' => '\1um',
            '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)(sis|ses)$/' => '\1sis',
            '/(^analy)(sis|ses)$/' => '\1sis',
            '/([^f])ves$/' => '\1fe',
            '/(hive)s$/' => '\1',
            '/(tive)s$/' => '\1',
            '/([lr])ves$/' => '\1f',
            '/([^aeiouy]|qu)ies$/' => '\1y',
            '/(x|ch|ss|sh)es$/' => '\1',
            '/(bus)(es)?$/' => '\1',
            '/(o)es$/' => '\1',
            '/(shoe)s$/' => '\1',
            '/(cris|test)(is|es)$/' => '\1is',
            '/^(a)x[ie]s$/' => '\1xis',
            '/(alias|status)(es)?$/' => '\1',
            '/(vert|ind)ices$/' => '\1ex',
            '/(matr)ices$/' => '\1ix',
            '/(quiz)zes$/' => '\1',
            '/(database)s$/' => '\1',
        ]);

        $this->er2ing = array_reverse([
            '/ers?$/' => 'ing',
            '/(be|draw|liv)ers?$/' => '\0',
        ]);

        $this->ing2er = array_reverse([
            '/ing$/' => 'er',
            '/(snow|rain)ing$/' => '\1',
            '/(th|hous|dur|spr|wedd)ing$/' => '\0',
            '/(liv|draw)ing$/' => '\0',
        ]);

    }

    /**
     * @return array<int, string>
     */
    public function get_variants(string $word): array
    {
        $res = [];

        $lword = strtolower($word);

        $rc = $this->exceptions[$lword] ?? null;
        if (isset($rc)) {
            if (is_string($rc) && $rc !== '') {
                $res[] = $rc;
            }
            return $res;
        }

        self::run($this->pluralizers, $word, $res);
        self::run($this->singularizers, $word, $res);
        if (strlen($word) > 4) {
            self::run($this->er2ing, $word, $res);
        }
        if (strlen($word) > 5) {
            $rc = self::run($this->ing2er, $word, $res);
            if ($rc !== false) {
                self::run($this->pluralizers, $rc, $res);
            }
        }
        return $res;
    }

    /**
     * @param array<string, string> $rules always one of this class's own
     *   fixed, valid regex literal arrays
     * @param array<int, string> $res
     */
    private static function run(array $rules, string $word, array &$res): string|false
    {
        foreach ($rules as $rule => $replacement) {
            $rc = preg_replace($rule . 'i', $replacement, $word, -1, $count);
            if ((bool) $count) {
                // fixed, valid pattern -- preg_replace() only returns null
                // on a compile error, which is unreachable here.
                assert($rc !== null);
                if ($rc !== $word) {
                    $res[] = $rc;
                    return $rc;
                }
                break;
            }
        }
        return false;
    }
}
