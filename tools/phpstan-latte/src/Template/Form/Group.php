<?php

declare(strict_types=1);

namespace Efabrica\PHPStanLatte\Template\Form;

use function array_map;

use Efabrica\PHPStanLatte\Template\Form\Behavior\ControlHolderBehavior;
use Efabrica\PHPStanLatte\Template\NameItem;

use function json_encode;

use JsonSerializable;

use function md5;

use PHPStan\PhpDoc\TypeStringResolver;
use ReturnTypeWillChange;

final class Group implements NameItem, ControlHolderInterface, JsonSerializable
{
    use ControlHolderBehavior;

    private string $name;

    /**
     * @param ControlInterface[] $controls
     */
    public function __construct(string $name, array $controls = [])
    {
        $this->name = $name;
        $this->addControls($controls);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSignatureHash(): string
    {
        return md5((string)json_encode([
            'name' => $this->name,
            'controls' => array_map(fn (ControlInterface $control) => $control->getSignatureHash(), $this->controls),
        ]));
    }

    #[ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'name' => $this->name,
            'controls' => array_map(fn (ControlInterface $control) => $control->jsonSerialize(), $this->controls),
        ];
    }

    /**
     * @param array{name?: string, controls?: array<array<string, mixed>>} $data
     */
    public static function fromJson(array $data, TypeStringResolver $typeStringResolver): self
    {
        $controls = [];
        if (isset($data['controls'])) {
            foreach ($data['controls'] as $controlData) {
                $controls[] = Form::controlFromJson($controlData, $typeStringResolver);
            }
        }

        return new self($data['name'] ?? '', $controls);
    }
}
