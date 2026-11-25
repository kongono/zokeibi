<?php

declare(strict_types=1);

namespace Zokeibi\Studio;

use InvalidArgumentException;
use Zokeibi\Compiler\BuildReport;
use Zokeibi\Compiler\CssTreeShaker;
use RuntimeException;

/**
 * Atomic Git-tracked persistence for Zokeibi Studio design tokens (`zokeibi.json`).
 */
final readonly class StudioStore
{
    public function configPath(string $projectDir): string
    {
        return rtrim($projectDir, '\\/') . DIRECTORY_SEPARATOR . 'zokeibi.json';
    }

    /**
     * Resolves a config-supplied relative path safely inside `$projectDir`,
     * rejecting absolute paths, `..` traversal, control characters, and symlink escapes.
     */
    public function resolveSafeProjectPath(string $projectDir, string $relativePath): string
    {
        $root = rtrim($projectDir, '\\/');
        if ('' === $root || is_link($root)) {
            throw new InvalidArgumentException('Zokeibi project directory must be a non-symlink directory.');
        }

        $trimmed = trim($relativePath);
        if ('' === $trimmed || 1 === preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            throw new InvalidArgumentException('Invalid Zokeibi relative path.');
        }
        // Reject absolute paths (POSIX `/...`, Windows `C:\...`, UNC `\\...`)
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '\\') || 1 === preg_match('/\A[a-zA-Z]:/', $trimmed)) {
            throw new InvalidArgumentException(sprintf('Zokeibi config path "%s" must be relative to the project root.', $relativePath));
        }

        $segments = preg_split('#[/\\\\]+#', $trimmed);
        if (false === $segments) {
            throw new InvalidArgumentException('Invalid Zokeibi relative path.');
        }

        $clean = [];
        foreach ($segments as $seg) {
            if ('' === $seg || '.' === $seg) {
                continue;
            }
            if ('..' === $seg) {
                throw new InvalidArgumentException(sprintf('Path traversal is not allowed in Zokeibi config path "%s".', $relativePath));
            }
            $clean[] = $seg;
        }

        if ([] === $clean) {
            throw new InvalidArgumentException('Zokeibi relative path cannot be empty.');
        }

        $resolvedRoot = realpath($root);
        $cursor = false !== $resolvedRoot ? $resolvedRoot : $root;
        foreach ($clean as $seg) {
            $cursor .= DIRECTORY_SEPARATOR . $seg;
            if (is_link($cursor)) {
                throw new InvalidArgumentException(sprintf('Symlink path segment is not allowed in Zokeibi config path "%s".', $relativePath));
            }
        }

        return $cursor;
    }

    public function load(string $projectDir): ThemeTokenConfig
    {
        $path = $this->configPath($projectDir);
        if (!is_file($path)) {
            return ThemeTokenConfig::defaults();
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            return ThemeTokenConfig::defaults();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ThemeTokenConfig::defaults();
        }

        return ThemeTokenConfig::fromArray($decoded);
    }

    /**
     * Reads raw `zokeibi.json` contents (pretty JSON) for GUI inspection.
     */
    public function readRawJson(string $projectDir): string
    {
        $path = $this->configPath($projectDir);
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (false !== $raw) {
                return $raw;
            }
        }

        return json_encode(
            ['studio' => $this->load($projectDir)->toArray()],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /**
     * Atomically updates the `studio` section of `zokeibi.json` while preserving other fields.
     */
    public function save(string $projectDir, ThemeTokenConfig $config): void
    {
        $root = rtrim($projectDir, '\\/');
        if (is_link($root)) {
            throw new RuntimeException('Refusing to write zokeibi.json into a symlinked directory.');
        }
        if (!is_dir($root) && !mkdir($root, 0o775, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create Zokeibi project directory.');
        }

        $path = $this->configPath($root);
        if (is_link($path)) {
            throw new RuntimeException('Refusing to overwrite symlinked zokeibi.json.');
        }
        $existing = [
            'zokeibi' => '1.0.1',
            'theme' => $config->activeTheme,
            'radius' => $config->radiusPreset,
            'views_dir' => 'views',
            'css_out' => 'public/assets/zokeibi.min.css',
            'installed' => [],
        ];

        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (false !== $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
        }

        $existing['theme'] = $config->activeTheme;
        $existing['radius'] = $config->radiusPreset;
        $existing['studio'] = $config->toArray();

        $encoded = json_encode($existing, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (false === file_put_contents($tmp, $encoded)) {
            throw new RuntimeException('Unable to write zokeibi.json.');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to atomically save zokeibi.json.');
        }
    }

    /**
     * Saves updated Studio tokens to `zokeibi.json` and immediately recompiles the project's CSS bundle.
     */
    public function saveAndBuild(
        string $projectDir,
        ThemeTokenConfig $config,
        CssTreeShaker $compiler,
    ): BuildReport {
        $this->save($projectDir, $config);

        $root = rtrim($projectDir, '\\/');
        $viewsRel = 'views';
        $cssOutRel = 'public/assets/zokeibi.min.css';

        $path = $this->configPath($root);
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (false !== $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (isset($decoded['views_dir']) && is_string($decoded['views_dir']) && '' !== trim($decoded['views_dir'])) {
                        $viewsRel = $decoded['views_dir'];
                    }
                    if (isset($decoded['css_out']) && is_string($decoded['css_out']) && '' !== trim($decoded['css_out'])) {
                        $cssOutRel = $decoded['css_out'];
                    }
                }
            }
        }

        $viewsDir = $this->resolveSafeProjectPath($root, $viewsRel);
        $outCss = $this->resolveSafeProjectPath($root, $cssOutRel);

        return $compiler->buildFromDirectory($viewsDir, $outCss, false, [], $config);
    }
}
