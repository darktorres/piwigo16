<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use JsonSerializable;
use Override;

/**
 * One tag in the admin tag manager's list.
 *
 * `jsonSerialize()` is the wire contract with `themes/admin/default/js/tags.ts`'s
 * own `TagRow` interface: snake_case keys, and `counter`/`alt_names` omitted
 * rather than null when they carry nothing.
 *
 * `$counter` is a plain int here even though the JSON key is optional,
 * because 0 and "absent" have always meant the same thing to every reader.
 * `tags.latte` said so itself, by branching on the key's presence and then
 * passing `has_image: false, tag_count: 0` down the missing side -- the
 * exact pair `counter: 0` produces on the other one.
 */
final readonly class TagRow implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
        public string $rawName,
        public string $urlName,
        public int $counter,
        public ?string $altNames,
    ) {}

    /**
     * @return array<string, int|string>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $row = [
            'name' => $this->name,
            'id' => $this->id,
            'url_name' => $this->urlName,
            'raw_name' => $this->rawName,
        ];

        if ($this->counter > 0) {
            $row['counter'] = $this->counter;
        }

        if ($this->altNames !== null) {
            $row['alt_names'] = $this->altNames;
        }

        return $row;
    }
}
