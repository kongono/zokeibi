<?php

declare(strict_types=1);

namespace Zokeibi\Registry;

/**
 * Immutable machine-readable schema for a single Zokeibi UI component.
 *
 * @phpstan-type PropDef array{
 *     type: string,
 *     enum?: list<string>,
 *     default?: string|int|float|bool,
 *     description: string
 * }
 */
final readonly class ComponentSchema
{
    /**
     * @param list<string>          $standards
     * @param list<string>          $cssDeps
     * @param array<string, PropDef> $props
     */
    public function __construct(
        public string $id,
        public string $tag,
        public string $name,
        public string $category,
        public string $jsMode,
        public string $description,
        public array $standards,
        public string $css,
        public array $cssDeps,
        public string $template,
        public array $props,
        public string $example,
    ) {}

    /**
     * Returns allowed enum values for a prop, or null if not enum-constrained.
     *
     * @return list<string>|null
     */
    public function enumValues(string $propName): ?array
    {
        $def = $this->props[$propName] ?? null;
        if (null === $def || !isset($def['enum'])) {
            return null;
        }

        return $def['enum'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->tag,
            'name' => $this->name,
            'category' => $this->category,
            'js_mode' => $this->jsMode,
            'description' => $this->description,
            'standards' => $this->standards,
            'css' => $this->css,
            'css_deps' => $this->cssDeps,
            'template' => $this->template,
            'props' => $this->props,
            'example' => $this->example,
        ];
    }
}
