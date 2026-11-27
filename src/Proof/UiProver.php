<?php

declare(strict_types=1);

namespace Zokeibi\Proof;

use InvalidArgumentException;
use Zokeibi\Audit\UiAuditor;
use Zokeibi\Compiler\CssTreeShaker;
use Zokeibi\Studio\OklchContrast;
use Zokeibi\Studio\StudioStore;
use Zokeibi\Studio\ThemeTokenConfig;

/**
 * Executable UI, Accessibility & Performance Proof Gate (`zokeibi prove`).
 *
 * Proves three mathematical UI guarantees in one command:
 *   1. WCAG 2.1 AA contrast across active theme + EVERY saved custom brand theme in `zokeibi.json`
 *   2. Static UI schema, ARIA & strict-CSP audit (`UiAuditor`)
 *   3. Tree-shaken gzipped CSS bundle budget (`--max-gzip-kb=12.0`)
 */
final readonly class UiProver
{
    public function __construct(
        private UiAuditor $auditor,
        private CssTreeShaker $compiler,
        private StudioStore $studio,
    ) {}

    /**
     * @return array{
     *     passed: bool,
     *     grade: string,
     *     score: int,
     *     elapsed_ms: float,
     *     pillars: array{
     *         contrast: array{passed: bool, score: int, themes_checked: int, failures: list<string>},
     *         audit: array{passed: bool, score: int, scanned_files: int, errors: int, warnings: int},
     *         budget: array{passed: bool, score: int, gzip_bytes: int, gzip_kb: float, max_gzip_kb: float, components_used: int}
     *     }
     * }
     */
    public function prove(
        string $projectDir,
        ?string $viewsDir = null,
        float $maxGzipKb = 12.5,
        bool $strict = false,
    ): array {
        if (!is_finite($maxGzipKb) || $maxGzipKb <= 0.0) {
            throw new InvalidArgumentException('CSS gzip budget must be a finite positive number.');
        }

        $t0 = hrtime(true);
        $root = rtrim($projectDir, '\\/');
        $resolvedViews = $viewsDir;
        if (null === $resolvedViews) {
            $configPath = $this->studio->configPath($root);
            $viewsRel = 'views';
            if (is_file($configPath) && !is_link($configPath)) {
                $rawCfg = file_get_contents($configPath);
                if (false !== $rawCfg) {
                    $decodedCfg = json_decode($rawCfg, true);
                    if (is_array($decodedCfg) && isset($decodedCfg['views_dir']) && is_string($decodedCfg['views_dir']) && '' !== trim($decodedCfg['views_dir'])) {
                        $viewsRel = $decodedCfg['views_dir'];
                    }
                }
            }
            $resolvedViews = $this->studio->resolveSafeProjectPath($root, $viewsRel);
        }

        // Pillar 1: WCAG 2.1 AA Contrast across active theme AND all saved custom themes
        $config = $this->studio->load($root);
        $contrastFailures = [];
        $themesChecked = 1;

        foreach (OklchContrast::auditConfig($config) as $check) {
            if (!$check->passesAa) {
                $contrastFailures[] = sprintf(
                    'Active theme "%s": %s contrast %.2f:1 (%s) is below WCAG 2.1 AA (4.5:1).',
                    $config->activeTheme,
                    $check->label,
                    $check->ratio,
                    $check->grade,
                );
            }
        }

        foreach ($config->customThemes as $themeName => $def) {
            ++$themesChecked;
            $customCfg = new ThemeTokenConfig(
                activeTheme: $themeName,
                hue: $def['hue'],
                chroma: $def['chroma'],
                accentLightness: $def['accent_lightness'],
                mode: $def['mode'],
                radiusPx: $def['radius_px'],
            );
            foreach (OklchContrast::auditConfig($customCfg) as $check) {
                if (!$check->passesAa) {
                    $contrastFailures[] = sprintf(
                        'Custom theme "%s": %s contrast %.2f:1 (%s) is below WCAG 2.1 AA (4.5:1).',
                        $themeName,
                        $check->label,
                        $check->ratio,
                        $check->grade,
                    );
                }
            }
        }

        $contrastPassed = [] === $contrastFailures;
        $contrastScore = max(0, 100 - (count($contrastFailures) * 25));

        // Pillar 2: Static UI Schema, ARIA & Strict-CSP Audit
        $audit = $this->auditor->auditDirectory($resolvedViews);
        $auditPassed = $audit->passed && (!$strict || 0 === $audit->warningCount);
        $auditScore = $audit->score;

        // Pillar 3: Tree-Shaken Gzipped CSS Performance Budget
        $build = $this->compiler->buildFromDirectory($resolvedViews, null, false, [], $config);
        $gzipKb = round($build->gzipBytes / 1024, 2);
        $budgetPassed = $gzipKb <= $maxGzipKb;
        $budgetScore = $budgetPassed
            ? 100
            : max(0, 100 - (int) ceil(($gzipKb - $maxGzipKb) * 15));

        $overallScore = (int) round(($contrastScore + $auditScore + $budgetScore) / 3);
        $allPassed = $contrastPassed && $auditPassed && $budgetPassed;
        $grade = match (true) {
            $allPassed && $overallScore >= 98 => 'A+',
            $allPassed && $overallScore >= 90 => 'A',
            $overallScore >= 80 => 'B',
            $overallScore >= 70 => 'C',
            $overallScore >= 60 => 'D',
            default => 'F',
        };

        return [
            'passed' => $allPassed,
            'grade' => $grade,
            'score' => $overallScore,
            'elapsed_ms' => round((hrtime(true) - $t0) / 1_000_000, 2),
            'pillars' => [
                'contrast' => [
                    'passed' => $contrastPassed,
                    'score' => $contrastScore,
                    'themes_checked' => $themesChecked,
                    'failures' => $contrastFailures,
                ],
                'audit' => [
                    'passed' => $auditPassed,
                    'score' => $auditScore,
                    'scanned_files' => $audit->scannedFiles,
                    'errors' => $audit->errorCount,
                    'warnings' => $audit->warningCount,
                ],
                'budget' => [
                    'passed' => $budgetPassed,
                    'score' => $budgetScore,
                    'gzip_bytes' => $build->gzipBytes,
                    'gzip_kb' => $gzipKb,
                    'max_gzip_kb' => $maxGzipKb,
                    'components_used' => count($build->usedComponents),
                ],
            ],
        ];
    }
}
