<?php

declare(strict_types=1);

namespace Zokeibi\Installer;

use InvalidArgumentException;
use Zokeibi\Registry\ComponentRegistry;
use RuntimeException;

/**
 * Shadcn-style full-ownership component installer & CSS layer ejector for Kongono apps.
 */
final readonly class ComponentInstaller
{
    public function __construct(
        private ComponentRegistry $registry,
    ) {}

    public function init(string $projectDir, string $theme = 'sumi', string $radius = 'default'): InstallReport
    {
        if (!in_array($theme, $this->registry->themes(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown Zokeibi theme "%s".', $theme));
        }
        if (!in_array($radius, $this->registry->radiusPresets(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown Zokeibi radius preset "%s".', $radius));
        }

        $root = rtrim($projectDir, '\\/');
        $this->ensureDir($root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui');
        $this->ensureDir($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets');

        $written = [];
        $skipped = [];
        $configPath = $root . DIRECTORY_SEPARATOR . 'zokeibi.json';

        if (!is_file($configPath)) {
            $config = [
                'zokeibi' => $this->registry->version(),
                'theme' => $theme,
                'radius' => $radius,
                'views_dir' => 'views',
                'css_out' => 'public/assets/zokeibi.min.css',
                'installed' => [],
            ];
            $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (false === file_put_contents($configPath, $json)) {
                throw new RuntimeException('Unable to write zokeibi.json.');
            }
            $written[] = 'zokeibi.json';
        } else {
            $skipped[] = 'zokeibi.json';
        }

        return new InstallReport('init', $root, $written, $skipped);
    }

    /**
     * Copies `<x-ui.*>` component templates into the project's `views/components/ui/` directory.
     *
     * @param list<string> $componentIds
     */
    public function add(string $projectDir, array $componentIds, bool $force = false): InstallReport
    {
        $root = rtrim($projectDir, '\\/');
        $targetDir = $root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui';
        $this->ensureDir($targetDir);

        $written = [];
        $skipped = [];
        $installedIds = [];

        foreach ($componentIds as $id) {
            $schema = $this->registry->get($id);
            $source = $this->registry->templatePath($schema->template);
            if (!is_file($source)) {
                throw new RuntimeException(sprintf('Source template missing for component "%s".', $id));
            }
            $dest = $targetDir . DIRECTORY_SEPARATOR . $schema->template;
            $rel = 'views/components/ui/' . $schema->template;
            $installedIds[] = $id;

            if (is_file($dest) && !$force) {
                $skipped[] = $rel;
                continue;
            }

            $contents = file_get_contents($source);
            if (false === $contents || false === file_put_contents($dest, $contents)) {
                throw new RuntimeException(sprintf('Unable to install component "%s".', $id));
            }
            $written[] = $rel;
        }

        $this->recordInstalled($root, $installedIds);

        return new InstallReport('add', $root, $written, $skipped);
    }

    /**
     * Ejects raw OKLCH CSS layers and component stylesheets into `resources/zokeibi-css/` for full customization.
     *
     * @param list<string> $componentIds
     */
    public function ejectCss(string $projectDir, array $componentIds, bool $force = false): InstallReport
    {
        $root = rtrim($projectDir, '\\/');
        $cssTarget = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'zokeibi-css';
        $compTarget = $cssTarget . DIRECTORY_SEPARATOR . 'components';
        $this->ensureDir($compTarget);

        $written = [];
        $skipped = [];

        $coreFiles = [...$this->registry->coreCssFiles(), $this->registry->utilitiesCssFile()];
        foreach ($coreFiles as $coreFile) {
            $src = $this->registry->cssPath($coreFile);
            $dst = $cssTarget . DIRECTORY_SEPARATOR . basename($coreFile);
            $rel = 'resources/zokeibi-css/' . basename($coreFile);
            $this->copyOne($src, $dst, $rel, $force, $written, $skipped);
        }

        foreach ($componentIds as $id) {
            $schema = $this->registry->get($id);
            $src = $this->registry->cssPath('components/' . $schema->css);
            $dst = $compTarget . DIRECTORY_SEPARATOR . basename($schema->css);
            $rel = 'resources/zokeibi-css/components/' . basename($schema->css);
            $this->copyOne($src, $dst, $rel, $force, $written, $skipped);
        }

        return new InstallReport('eject', $root, $written, $skipped);
    }

    /**
     * Installs the optional zero-dependency, strict-CSP `zokeibi-runtime.js` helper
     * for non-Live frontends (plain HTML, HTMX, Blade, Twig, Laravel, Slim).
     */
    public function installRuntime(string $projectDir, ?string $outputPath = null, bool $force = false): InstallReport
    {
        $root = rtrim($projectDir, '\\/');
        $src = $this->registry->resourcesDir() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'zokeibi-runtime.js';
        if (!is_file($src)) {
            throw new RuntimeException('Zokeibi vanilla runtime source not found.');
        }

        $dest = (null !== $outputPath && '' !== trim($outputPath))
            ? $outputPath
            : $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zokeibi-runtime.js';
        $this->ensureDir(dirname($dest));

        $written = [];
        $skipped = [];
        $rel = str_replace('\\', '/', $dest);
        $this->copyOne($src, $dest, $rel, $force, $written, $skipped);

        return new InstallReport('runtime', $root, $written, $skipped);
    }

    /**
     * @param list<string> $written
     * @param list<string> $skipped
     */
    private function copyOne(
        string $src,
        string $dst,
        string $rel,
        bool $force,
        array &$written,
        array &$skipped,
    ): void {
        if (!is_file($src)) {
            throw new RuntimeException(sprintf('Missing stylesheet "%s".', basename($src)));
        }
        if (is_file($dst) && !$force) {
            $skipped[] = $rel;

            return;
        }
        $contents = file_get_contents($src);
        if (false === $contents || false === file_put_contents($dst, $contents)) {
            throw new RuntimeException(sprintf('Unable to eject stylesheet "%s".', basename($src)));
        }
        $written[] = $rel;
    }

    /**
     * @param list<string> $ids
     */
    private function recordInstalled(string $root, array $ids): void
    {
        $configPath = $root . DIRECTORY_SEPARATOR . 'zokeibi.json';
        if (!is_file($configPath)) {
            return;
        }
        $raw = file_get_contents($configPath);
        if (false === $raw) {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }
        $existing = [];
        if (isset($decoded['installed']) && is_array($decoded['installed'])) {
            foreach ($decoded['installed'] as $item) {
                if (is_string($item)) {
                    $existing[$item] = true;
                }
            }
        }
        foreach ($ids as $id) {
            $existing[$id] = true;
        }
        $merged = array_keys($existing);
        sort($merged);
        $decoded['installed'] = $merged;

        $json = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($configPath, $json);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $dir));
        }
    }
}
