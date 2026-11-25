<?php

declare(strict_types=1);

namespace Zokeibi\Watch;

use Closure;
use FilesystemIterator;
use Zokeibi\Compiler\BuildReport;
use Zokeibi\Compiler\CssTreeShaker;
use Zokeibi\Studio\StudioStore;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Zero-Node.js pure PHP watcher (`zokeibi watch`) bridging Zokeibi Studio / templates
 * directly into Kongono's Zero-Reload CSS Hot Swap (`css_hash` stylesheet swap).
 */
final readonly class CssWatcher
{
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

    /** @var list<string> */
    private const array WATCHED_EXTENSIONS = [
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

    public function __construct(
        private CssTreeShaker $compiler,
        private StudioStore $studio,
    ) {}

    public function fingerprint(string $projectDir, string $viewsDir): string
    {
        clearstatcache();

        $parts = [];
        $configPath = $this->studio->configPath($projectDir);
        if (is_file($configPath) && !is_link($configPath)) {
            $mtime = @filemtime($configPath);
            $size = @filesize($configPath);
            if (false !== $mtime && false !== $size) {
                $parts[] = 'zokeibi.json:' . (string) $mtime . ':' . (string) $size;
            }
        }

        if (is_dir($viewsDir)) {
            $dirIterator = new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS);
            $filterIterator = new RecursiveCallbackFilterIterator(
                $dirIterator,
                static function (SplFileInfo $current): bool {
                    if ($current->isLink()) {
                        return false;
                    }
                    if ($current->isDir()) {
                        return !in_array(strtolower($current->getFilename()), self::IGNORED_DIRS, true);
                    }

                    return true;
                },
            );
            $it = new RecursiveIteratorIterator($filterIterator);
            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }
                $name = strtolower($file->getFilename());
                $watched = false;
                foreach (self::WATCHED_EXTENSIONS as $ext) {
                    if (str_ends_with($name, $ext)) {
                        $watched = true;
                        break;
                    }
                }
                if (!$watched) {
                    continue;
                }
                try {
                    $parts[] = $file->getPathname() . ':' . (string) $file->getMTime() . ':' . (string) $file->getSize();
                } catch (RuntimeException) {
                    // File removed concurrently during watch scan
                    continue;
                }
            }
        }
        sort($parts, SORT_STRING);

        return substr(hash('xxh64', implode('|', $parts)), 0, 12);
    }

    public function rebuild(string $projectDir, string $viewsDir, string $outCss): BuildReport
    {
        $config = $this->studio->load($projectDir);

        return $this->compiler->buildFromDirectory($viewsDir, $outCss, false, [], $config);
    }

    /**
     * @param (Closure(string): void)|null $writer
     */
    public function watch(
        string $projectDir,
        string $viewsDir,
        string $outCss,
        int $intervalMs = 400,
        bool $once = false,
        ?Closure $writer = null,
        ?int $maxIterations = null,
    ): BuildReport {
        $emit = $writer ?? static function (string $line): void {
            fwrite(STDOUT, $line);
            fflush(STDOUT);
        };

        $lastHash = $this->fingerprint($projectDir, $viewsDir);
        $report = $this->rebuild($projectDir, $viewsDir, $outCss);
        $emit(sprintf(
            "[%s] Zokeibi CSS built -> %s (%d components, %.2f KB gzip)\n",
            gmdate('H:i:s'),
            $outCss,
            count($report->usedComponents),
            $report->gzipBytes / 1024,
        ));

        if ($once) {
            return $report;
        }

        $sleepUs = max(100, min(5000, $intervalMs)) * 1000;
        for ($iter = 0; null === $maxIterations || $iter < $maxIterations; ++$iter) {
            usleep($sleepUs);
            $nextHash = $this->fingerprint($projectDir, $viewsDir);
            if ($nextHash === $lastHash) {
                continue;
            }
            $lastHash = $nextHash;
            $report = $this->rebuild($projectDir, $viewsDir, $outCss);
            $emit(sprintf(
                "[%s] Zokeibi CSS hot-rebuilt -> %s (%d components, %.2f KB gzip — Kongono Hot Reload will swap CSS in-place)\n",
                gmdate('H:i:s'),
                $outCss,
                count($report->usedComponents),
                $report->gzipBytes / 1024,
            ));
        }

        return $report;
    }
}
