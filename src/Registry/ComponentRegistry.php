<?php

declare(strict_types=1);

namespace Zokeibi\Registry;

use InvalidArgumentException;
use RuntimeException;

/**
 * Machine-readable AI-native registry of Zokeibi components, themes, and CSS layers.
 */
final readonly class ComponentRegistry
{
    private string $resourcesDir;

    /** @var array<string, ComponentSchema> */
    private array $components;

    /** @var list<string> */
    private array $themes;

    /** @var list<string> */
    private array $radiusPresets;

    /** @var list<string> */
    private array $coreCss;

    private string $utilitiesCss;
    private string $version;

    public function __construct(?string $resourcesDir = null)
    {
        $resolved = $resourcesDir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources';
        $this->resourcesDir = rtrim($resolved, '\\/');

        $registryFile = $this->resourcesDir . DIRECTORY_SEPARATOR . 'registry.json';
        if (!is_file($registryFile)) {
            throw new RuntimeException('Zokeibi registry manifest not found.');
        }

        $raw = file_get_contents($registryFile);
        if (false === $raw) {
            throw new RuntimeException('Unable to read Zokeibi registry manifest.');
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Zokeibi registry manifest format.');
        }

        $this->version = isset($decoded['version']) && is_string($decoded['version']) ? $decoded['version'] : '1.0.0';
        $this->themes = self::stringList($decoded['themes'] ?? []);
        $this->radiusPresets = self::stringList($decoded['radius_presets'] ?? []);
        $this->coreCss = self::stringList($decoded['core_css'] ?? []);
        $this->utilitiesCss = isset($decoded['utilities_css']) && is_string($decoded['utilities_css'])
            ? $decoded['utilities_css']
            : 'utilities.css';

        $components = [];
        $rawComponents = isset($decoded['components']) && is_array($decoded['components']) ? $decoded['components'] : [];
        foreach ($rawComponents as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            $components[$key] = self::hydrateSchema($key, $entry);
        }

        $this->components = $components;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function resourcesDir(): string
    {
        return $this->resourcesDir;
    }

    public function cssPath(string $relative): string
    {
        return $this->resourcesDir . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/\\'));
    }

    public function templatePath(string $templateFile): string
    {
        return $this->resourcesDir
            . DIRECTORY_SEPARATOR . 'views'
            . DIRECTORY_SEPARATOR . 'components'
            . DIRECTORY_SEPARATOR . 'ui'
            . DIRECTORY_SEPARATOR . basename($templateFile);
    }

    /**
     * @return list<string>
     */
    public function themes(): array
    {
        return $this->themes;
    }

    /**
     * @return list<string>
     */
    public function radiusPresets(): array
    {
        return $this->radiusPresets;
    }

    /**
     * @return list<string>
     */
    public function coreCssFiles(): array
    {
        return $this->coreCss;
    }

    public function utilitiesCssFile(): string
    {
        return $this->utilitiesCss;
    }

    /**
     * @return array<string, ComponentSchema>
     */
    public function all(): array
    {
        return $this->components;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->components);
    }

    public function count(): int
    {
        return count($this->components);
    }

    public function has(string $id): bool
    {
        return isset($this->components[$id]);
    }

    public function get(string $id): ComponentSchema
    {
        if (!isset($this->components[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown Zokeibi component "%s".', $id));
        }

        return $this->components[$id];
    }

    /**
     * @return array<string, ComponentSchema>
     */
    public function byCategory(string $category): array
    {
        $filtered = [];
        foreach ($this->components as $id => $schema) {
            if ($schema->category === $category) {
                $filtered[$id] = $schema;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $map = [];
        foreach ($this->components as $id => $schema) {
            $map[$id] = $schema->toArray();
        }

        return [
            'name' => 'zokeibi',
            'kanji' => '造形美',
            'package' => 'kongono/zokeibi',
            'version' => $this->version,
            'component_count' => count($map),
            'themes' => $this->themes,
            'radius_presets' => $this->radiusPresets,
            'components' => $map,
        ];
    }

    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->toArray(), $flags);
    }

    /**
     * @param array<mixed, mixed> $entry
     */
    private static function hydrateSchema(string $id, array $entry): ComponentSchema
    {
        $props = [];
        if (isset($entry['props']) && is_array($entry['props'])) {
            foreach ($entry['props'] as $propName => $rawProp) {
                if (!is_string($propName) || !is_array($rawProp)) {
                    continue;
                }
                $propDef = [
                    'type' => isset($rawProp['type']) && is_string($rawProp['type']) ? $rawProp['type'] : 'string',
                    'description' => isset($rawProp['description']) && is_string($rawProp['description']) ? $rawProp['description'] : '',
                ];
                if (isset($rawProp['enum']) && is_array($rawProp['enum'])) {
                    $propDef['enum'] = self::stringList($rawProp['enum']);
                }
                if (isset($rawProp['default']) && (is_string($rawProp['default']) || is_int($rawProp['default']) || is_float($rawProp['default']) || is_bool($rawProp['default']))) {
                    $propDef['default'] = $rawProp['default'];
                }
                $props[$propName] = $propDef;
            }
        }

        return new ComponentSchema(
            id: isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : $id,
            tag: isset($entry['tag']) && is_string($entry['tag']) ? $entry['tag'] : 'x-ui.' . $id,
            name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : ucfirst($id),
            category: isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : 'primitives',
            jsMode: isset($entry['js_mode']) && is_string($entry['js_mode']) ? $entry['js_mode'] : '0 B JS',
            description: isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : '',
            standards: self::stringList($entry['standards'] ?? []),
            css: isset($entry['css']) && is_string($entry['css']) ? $entry['css'] : $id . '.css',
            cssDeps: self::stringList($entry['css_deps'] ?? []),
            template: isset($entry['template']) && is_string($entry['template']) ? $entry['template'] : $id . '.kgn.html',
            props: $props,
            example: isset($entry['example']) && is_string($entry['example']) ? $entry['example'] : '',
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && '' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
