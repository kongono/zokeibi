<?php

declare(strict_types=1);

namespace Zokeibi\Compiler;

use FilesystemIterator;
use Zokeibi\Registry\ComponentRegistry;
use Zokeibi\Studio\StudioStore;
use Zokeibi\Studio\ThemeTokenConfig;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Pure PHP 8.5 CSS Tree-Shaker & Minifier (Zero Node, Zero npm, Zero PostCSS).
 *
 * Scans Kongono `.kgn.html` templates as well as framework-agnostic `.html`, `.php`, `.twig`,
 * `.jsx`, `.tsx`, `.vue`, and `.svelte` files for BOTH `<x-ui.*>` tags and `.z-*` component classes,
 * resolves transitive CSS dependencies + `zokeibi.json` safelist/Studio tokens, and emits minified CSS.
 */
final readonly class CssTreeShaker
{
    /** @var list<string> */
    private const array SCANNABLE_EXTENSIONS = [
        '.kgn.html',
        '.blade.php',
        '.html',
        '.php',
        '.twig',
        '.jsx',
        '.tsx',
        '.vue',
        '.svelte',
    ];

    /** @var list<string> */
    private const array IGNORED_DIRS = [
        'vendor',
        'node_modules',
        '.git',
        'var',
        'storage',
        'dist',
        '.cache',
    ];

    /**
     * Maps Zokeibi CSS class prefixes (for plain HTML, React, Vue, Svelte, HTMX, Twig, Blade)
     * directly to component IDs in `registry.json`.
     *
     * @var array<string, string>
     */
    private const array CLASS_PREFIX_MAP = [
        'z-btn' => 'button',
        'z-badge' => 'badge',
        'z-card' => 'card',
        'z-alert' => 'alert',
        'z-kbd' => 'kbd',
        'z-avatar' => 'avatar',
        'z-input' => 'input',
        'z-field' => 'input',
        'z-select' => 'select',
        'z-textarea' => 'textarea',
        'z-switch' => 'switch',
        'z-progress' => 'progress',
        'z-skeleton' => 'skeleton',
        'z-empty' => 'empty-state',
        'z-kpi' => 'kpi',
        'z-table' => 'table',
        'z-tabs' => 'tabs',
        'z-tablist' => 'tabs',
        'z-accordion' => 'accordion',
        'z-popover' => 'popover',
        'z-tooltip' => 'tooltip',
        'z-modal' => 'modal',
        'z-drawer' => 'drawer',
        'z-toast' => 'toast',
        'z-cmdk' => 'command-palette',
        'z-combobox' => 'combobox',
        'z-dropzone' => 'dropzone',
        'z-datepicker' => 'datepicker',
        'z-stepper' => 'stepper',
        'z-step' => 'stepper',
        'z-pagination' => 'pagination',
        'z-dr' => 'date-range',
        'z-ms' => 'multi-select',
        'z-chart' => 'chart',
        'z-editor' => 'editor',
        'z-otp' => 'otp-input',
        'z-copy' => 'copy-field',
        'z-timeline' => 'timeline',
        'z-breadcrumb' => 'breadcrumb',
        'z-checkbox' => 'checkbox',
        'z-radio' => 'radio-group',
        'z-dropdown' => 'dropdown',
        'z-segmented' => 'segmented',
        'z-seg-item' => 'segmented',
        'z-spinner' => 'spinner',
        'z-slider' => 'slider',
        'z-divider' => 'divider',
        'z-dl' => 'description-list',
        'z-codeblock' => 'code-block',
        'z-taginput' => 'tag-input',
        'z-banner' => 'banner',
        'z-rating' => 'rating',
        'z-sidebar' => 'sidebar',
        'z-input-group' => 'input-group',
        'z-num' => 'number-input',
        'z-avatar-group' => 'avatar-group',
        'z-carousel' => 'carousel',
        'z-tree' => 'tree',
        'z-pw' => 'password-input',
    ];

    public function __construct(
        private ComponentRegistry $registry,
    ) {}

    /**
     * Scans a views/source directory and builds a tree-shaken CSS bundle.
     *
     * @param list<string> $extraComponents
     */
    public function buildFromDirectory(
        string $viewsDir,
        ?string $outputPath = null,
        bool $includeAll = false,
        array $extraComponents = [],
        ?ThemeTokenConfig $studioConfig = null,
    ): BuildReport {
        $scannedFiles = 0;
        $discovered = [];

        if (is_dir($viewsDir)) {
            $dirIterator = new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS);
            $filterIterator = new RecursiveCallbackFilterIterator(
                $dirIterator,
                static function (SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::IGNORED_DIRS, true);
                    }

                    return true;
                },
            );
            $iterator = new RecursiveIteratorIterator($filterIterator);

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!$this->isScannableFile($path)) {
                    continue;
                }

                // Skip component definition templates themselves so unused installed primitives are not force-included
                $normalized = str_replace('\\', '/', $path);
                if (str_contains($normalized, '/components/ui/')) {
                    continue;
                }

                $contents = file_get_contents($path);
                if (false === $contents) {
                    continue;
                }
                $scannedFiles++;
                foreach ($this->detectComponentsInSource($contents) as $compId) {
                    $discovered[$compId] = true;
                }
            }
        }

        if ($includeAll) {
            foreach ($this->registry->ids() as $id) {
                $discovered[$id] = true;
            }
        }

        if (null === $studioConfig) {
            $projectRoot = dirname(rtrim($viewsDir, '\\/'));
            $store = new StudioStore();
            if (is_file($store->configPath($projectRoot))) {
                $studioConfig = $store->load($projectRoot);
            } elseif (is_file($store->configPath($viewsDir))) {
                $studioConfig = $store->load($viewsDir);
            }
        }

        if (null !== $studioConfig) {
            foreach ($studioConfig->safelist as $safeId) {
                if ($this->registry->has($safeId)) {
                    $discovered[$safeId] = true;
                }
            }
        }

        foreach ($extraComponents as $extra) {
            if ($this->registry->has($extra)) {
                $discovered[$extra] = true;
            }
        }

        $usedIds = $this->resolveWithDependencies(array_keys($discovered));

        return $this->compileBundle($usedIds, $scannedFiles, $outputPath, $studioConfig);
    }

    /**
     * Compiles a bundle for an explicit list of component IDs.
     *
     * @param list<string> $componentIds
     */
    public function buildForComponents(
        array $componentIds,
        ?string $outputPath = null,
        ?ThemeTokenConfig $studioConfig = null,
    ): BuildReport {
        $usedIds = $this->resolveWithDependencies($componentIds);

        return $this->compileBundle($usedIds, 0, $outputPath, $studioConfig);
    }

    /**
     * Detects BOTH Kongono `<x-ui.*>` component tags AND framework-agnostic `.z-*` class names
     * (React `className`, Vue, Svelte, HTMX, Twig, Blade, plain HTML).
     *
     * @return list<string>
     */
    public function detectComponentsInSource(string $source): array
    {
        $found = [];

        // 1. Kongono native <x-ui.*> component tags
        if (preg_match_all('/<x-ui\.([a-z0-9-]+)(?=[\s\/>])/i', $source, $tagMatches) > 0) {
            foreach ($tagMatches[1] as $rawId) {
                $id = strtolower($rawId);
                if ($this->registry->has($id)) {
                    $found[$id] = true;
                }
            }
        }

        // 2. Framework-agnostic CSS component classes (e.g. class="z-btn z-btn-primary" or className="z-modal")
        if (preg_match_all('/(?<![a-z0-9_-])(z-[a-z]+)(?=[-\s"\'`,;})>\]])/i', $source, $classMatches) > 0) {
            foreach ($classMatches[1] as $rawClass) {
                $prefix = strtolower($rawClass);
                if (isset(self::CLASS_PREFIX_MAP[$prefix])) {
                    $compId = self::CLASS_PREFIX_MAP[$prefix];
                    if ($this->registry->has($compId)) {
                        $found[$compId] = true;
                    }
                }
            }
        }

        $ids = array_keys($found);
        sort($ids);

        return $ids;
    }

    /**
     * Pure PHP CSS minifier that safely preserves OKLCH, calc(), color-mix(), and @layer syntax.
     */
    public function minify(string $css): string
    {
        $out = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);
        if (null === $out) {
            $out = $css;
        }

        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $out = preg_replace('/\s*([{};,])\s*/', '$1', $out) ?? $out;
        $out = preg_replace('/;\}/', '}', $out) ?? $out;
        $out = preg_replace('/([a-z0-9-])\s*:\s+/i', '$1:', $out) ?? $out;

        return trim($out);
    }

    private function isScannableFile(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::SCANNABLE_EXTENSIONS as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $componentIds
     * @return list<string>
     */
    private function resolveWithDependencies(array $componentIds): array
    {
        $resolved = [];
        $queue = [];
        foreach ($componentIds as $id) {
            if ($this->registry->has($id)) {
                $queue[] = $id;
            }
        }

        while ([] !== $queue) {
            $current = array_shift($queue);
            if (isset($resolved[$current])) {
                continue;
            }
            $resolved[$current] = true;
            $schema = $this->registry->get($current);
            foreach ($schema->cssDeps as $dep) {
                if ($this->registry->has($dep) && !isset($resolved[$dep])) {
                    $queue[] = $dep;
                }
            }
        }

        $ordered = [];
        foreach ($this->registry->ids() as $id) {
            if (isset($resolved[$id])) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $usedIds
     */
    private function compileBundle(
        array $usedIds,
        int $scannedFiles,
        ?string $outputPath,
        ?ThemeTokenConfig $studioConfig = null,
    ): BuildReport {
        $rawCss = $this->assembleCss($usedIds, $studioConfig);
        $banner = sprintf(
            "/* Zokeibi UI v%s (造形美) — Pure PHP Tree-Shaken CSS (%d/%d components) */\n",
            $this->registry->version(),
            count($usedIds),
            $this->registry->count(),
        );
        $minified = $banner . $this->minify($rawCss) . "\n";

        $fullMinified = $banner . $this->minify($this->assembleCss($this->registry->ids(), $studioConfig)) . "\n";
        $fullBytes = strlen($fullMinified);
        $minBytes = strlen($minified);
        $savings = max(0.0, round((1 - ($minBytes / $fullBytes)) * 100, 1));

        $gz = gzencode($minified, 9);
        $gzipBytes = false !== $gz ? strlen($gz) : $minBytes;

        $skipped = [];
        foreach ($this->registry->ids() as $id) {
            if (!in_array($id, $usedIds, true)) {
                $skipped[] = $id;
            }
        }

        if (null !== $outputPath && '' !== $outputPath) {
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create CSS output directory.');
            }
            $tmp = $outputPath . '.tmp.' . bin2hex(random_bytes(4));
            if (false === file_put_contents($tmp, $minified)) {
                throw new RuntimeException('Unable to write compiled Zokeibi CSS.');
            }
            if (!rename($tmp, $outputPath)) {
                @unlink($tmp);
                throw new RuntimeException('Unable to atomically publish compiled Zokeibi CSS.');
            }
        }

        return new BuildReport(
            usedComponents: $usedIds,
            skippedComponents: $skipped,
            scannedFiles: $scannedFiles,
            rawBytes: strlen($rawCss),
            minifiedBytes: $minBytes,
            gzipBytes: $gzipBytes,
            fullBundleMinBytes: $fullBytes,
            treeShakeSavingsPercent: $savings,
            outputPath: $outputPath,
            css: $minified,
        );
    }

    /**
     * @param list<string> $componentIds
     */
    private function assembleCss(array $componentIds, ?ThemeTokenConfig $studioConfig = null): string
    {
        $chunks = [];

        foreach ($this->registry->coreCssFiles() as $coreFile) {
            $chunks[] = $this->readCssFile($this->registry->cssPath($coreFile));
        }

        if (null !== $studioConfig) {
            $chunks[] = $studioConfig->toCssLayer();
        }

        foreach ($componentIds as $id) {
            $schema = $this->registry->get($id);
            $chunks[] = $this->readCssFile($this->registry->cssPath('components/' . $schema->css));
        }

        $chunks[] = $this->readCssFile($this->registry->cssPath($this->registry->utilitiesCssFile()));

        return implode("\n\n", $chunks);
    }

    private function readCssFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Missing Zokeibi stylesheet "%s".', basename($path)));
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read Zokeibi stylesheet "%s".', basename($path)));
        }

        return $contents;
    }
}
