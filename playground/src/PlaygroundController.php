<?php

declare(strict_types=1);

namespace Zokeibi\Playground;

use Zokeibi\Zokeibi;
use Kongono\Core\Health\HealthCheck;
use Kongono\Http\Response;
use Kongono\Http\Routing\Get;
use Kongono\View\ViewEngine;

/**
 * Interactive Zokeibi (造形美) Playground & OKLCH Theme Studio controller.
 */
final readonly class PlaygroundController
{
    public function __construct(
        private ViewEngine $views,
        private Zokeibi $zokeibi,
        private HealthCheck $health,
        private string $playgroundDir,
        private string $studioProjectDir,
    ) {}

    #[Get('/', name: 'zokeibi.home')]
    public function home(): Response
    {
        $viewsDir = $this->playgroundDir . DIRECTORY_SEPARATOR . 'views';
        $studioConfig = $this->zokeibi->studio->load($this->studioProjectDir);

        // Compute real live telemetry from Zokeibi's pure PHP compiler & auditor
        $fullBuild = $this->zokeibi->compiler->buildFromDirectory($viewsDir, null, false, [], $studioConfig);
        $landingBuild = $this->zokeibi->compiler->buildForComponents(['button', 'card', 'badge']);
        $dashboardBuild = $this->zokeibi->compiler->buildForComponents(['button', 'badge', 'card', 'kpi', 'table', 'input', 'switch', 'modal']);
        $audit = $this->zokeibi->auditor->auditDirectory($viewsDir);
        $proof = $this->zokeibi->prover->prove($this->studioProjectDir, $viewsDir, strict: true);

        $schemas = [];
        foreach ($this->zokeibi->registry->all() as $id => $schema) {
            $schemas[$id] = [
                'id' => $schema->id,
                'tag' => $schema->tag,
                'name' => $schema->name,
                'category' => $schema->category,
                'js_mode' => $schema->jsMode,
                'description' => $schema->description,
                'standards' => implode(' · ', $schema->standards),
                'cli' => 'php bin/zokeibi add ' . $schema->id,
                'example' => $schema->example,
                'schema_json' => json_encode(
                    $schema->toArray(),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ];
        }

        $assetsDir = $this->playgroundDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets';
        $ver = static function (string $file) use ($assetsDir): string {
            $path = $assetsDir . DIRECTORY_SEPARATOR . $file;
            $ts = is_file($path) ? filemtime($path) : false;

            return false !== $ts ? (string) $ts : '1';
        };
        $assetVer = [
            'zokeibi' => $ver('zokeibi.min.css'),
            'playground' => $ver('playground.css'),
            'studio' => $ver('studio.js'),
            'runtime' => $ver('zokeibi-runtime.js'),
        ];

        $html = $this->views->render('pages/home', [
            'activeNav' => 'home',
            'assetVer' => $assetVer,
            'version' => $this->zokeibi->version(),
            'componentCount' => $this->zokeibi->registry->count(),
            'blockCount' => count($this->zokeibi->blocks->list()),
            'blocks' => $this->zokeibi->blocks->list(),
            'proof' => $proof,
            'themes' => $this->zokeibi->registry->themes(),
            'customThemes' => $studioConfig->customThemes,
            'schemas' => $schemas,
            'telemetry' => [
                'full_raw_kb' => sprintf('%.1f KB', $fullBuild->rawBytes / 1024),
                'full_min_kb' => sprintf('%.1f KB', $fullBuild->minifiedBytes / 1024),
                'full_gz_kb' => sprintf('%.1f KB', $fullBuild->gzipBytes / 1024),
                'full_used' => count($fullBuild->usedComponents),
                'landing_min_kb' => sprintf('%.1f KB', $landingBuild->minifiedBytes / 1024),
                'landing_gz_kb' => sprintf('%.1f KB', $landingBuild->gzipBytes / 1024),
                'landing_savings' => sprintf('%.1f%%', $landingBuild->treeShakeSavingsPercent),
                'landing_used' => count($landingBuild->usedComponents),
                'dash_min_kb' => sprintf('%.1f KB', $dashboardBuild->minifiedBytes / 1024),
                'dash_gz_kb' => sprintf('%.1f KB', $dashboardBuild->gzipBytes / 1024),
                'dash_savings' => sprintf('%.1f%%', $dashboardBuild->treeShakeSavingsPercent),
                'dash_used' => count($dashboardBuild->usedComponents),
                'audit_score' => $audit->score,
                'audit_errors' => $audit->errorCount,
                'audit_warnings' => $audit->warningCount,
                'audit_files' => $audit->scannedFiles,
                'proof_grade' => $proof['grade'],
                'proof_score' => $proof['score'],
            ],
        ]);

        return Response::html($html);
    }

    #[Get('/api/registry.json', name: 'zokeibi.registry')]
    public function registryJson(): Response
    {
        $payload = $this->zokeibi->registry->toArray();
        $studioConfig = $this->zokeibi->studio->load($this->studioProjectDir);
        $payload['studio'] = $studioConfig->toArray();
        $payload['blocks'] = $this->zokeibi->blocks->list();

        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return Response::text($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[Get('/api/v1/health', name: 'zokeibi.health')]
    public function health(): Response
    {
        $report = $this->health->run();

        return Response::json([
            'status' => $report['healthy'] ? 'ok' : 'degraded',
            'app' => 'zokeibi-playground',
            'version' => $this->zokeibi->version(),
            'components' => $this->zokeibi->registry->count(),
            'checks' => $report['checks'],
        ], $report['healthy'] ? 200 : 503);
    }
}
