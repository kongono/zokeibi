<?php

declare(strict_types=1);

namespace Zokeibi\Watch;

use Closure;
use FilesystemIterator;
use Zokeibi\Compiler\BuildReport;
use Zokeibi\Compiler\CssTreeShaker;
use Zokeibi\Studio\StudioStore;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Zero-Node.js pure PHP watcher (`zokeibi watch`) bridging Zokeibi Studio / templates
 * directly into Kongono's Zero-Reload CSS Hot Swap (`css_hash` stylesheet swap).
 */
final readonly class CssWatcher
{
    public function __construct(
        private CssTreeShaker $compiler,
        private StudioStore $studio,
    ) {}

    public function fingerprint(string $projectDir, string $viewsDir): string
    {
        $parts = [];
        $configPath = $this->studio->configPath($projectDir);
        if (is_file($configPath)) {
            $parts[] = 'zokeibi.json:' . (string) filemtime($configPath) . ':' . (string) filesize($configPath);
        }

        if (is_dir($viewsDir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $name = strtolower($file->getFilename());
                if (!str_ends_with($name, '.kgn.html') && !str_ends_with($name, '.html') && !str_ends_with($name, '.php')) {
                    continue;
                }
                $parts[] = $file->getPathname() . ':' . (string) $file->getMTime() . ':' . (string) $file->getSize();
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
