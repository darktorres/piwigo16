<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Creates a tree from a flat list of categories, no recursivity for high
 * speed. Split out of the former WsHelper god-class (P25 Stage 1 step 6).
 */
final readonly class CategoryTreeBuilder
{
    public function __construct(
        private XmlAttributeLists $xmlAttributeLists,
    ) {}

    /**
     * Each $categories row is genuinely arbitrary by design (a category
     * row, dynamically augmented in place with a 'sub_categories'
     * NamedArray) -- same rationale as AlbumsPageRenderer's own
     * dynamically-built tree.
     *
     * @param array<int|string, array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public function categoriesFlatlistToTree(array $categories): array
    {
        $tree = [];
        $key_of_cat = [];

        foreach ($categories as $key => &$node) {
            $cat_id = $node['id'];
            if (! is_int($cat_id) && ! is_string($cat_id)) {
                // malformed category row (missing/non-scalar id) -- cannot be
                // indexed or attached to a parent, skip it
                continue;
            }
            $key_of_cat[$cat_id] = $key;

            if (! isset($node['id_uppercat'])) {
                $tree[] = &$node;
            } else {
                $uppercat_id = $node['id_uppercat'];
                if (! is_int($uppercat_id) && ! is_string($uppercat_id)) {
                    continue;
                }
                if (! isset($key_of_cat[$uppercat_id])) {
                    // the parent isn't in this flat list (e.g. filtered out
                    // of scope upstream) -- attach at top level instead of
                    // indexing an undefined key.
                    $tree[] = &$node;
                    continue;
                }
                $uppercat_key = $key_of_cat[$uppercat_id];
                if (! isset($categories[$uppercat_key]['sub_categories'])) {
                    $categories[$uppercat_key]['sub_categories'] =
                      new NamedArray([], 'category', $this->xmlAttributeLists->stdGetCategoryXmlAttributes());
                }

                $sub_categories = $categories[$uppercat_key]['sub_categories'];
                if ($sub_categories instanceof NamedArray) {
                    $sub_categories->content[] = &$node;
                }
            }
        }

        return $tree;
    }
}
