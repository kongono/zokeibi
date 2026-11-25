<?php

declare(strict_types=1);

namespace Zokeibi\Compiler;

/**
 * Immutable telemetry report emitted by the Pure PHP CSS Tree-Shaker.
 */
final readonly class BuildReport
{
    /**
     * @param list<string> $usedComponents
     * @param list<string> $skippedComponents
     */
    public function __construct(
        public array $usedComponents,
        public array $skippedComponents,
        public int $scannedFiles,
        public int $rawBytes,
        public int $minifiedBytes,
        public int $gzipBytes,
        public int $fullBundleMinBytes,
        public float $treeShakeSavingsPercent,
        public ?string $outputPath,
        public string $css,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'used_components' => $this->usedComponents,
            'used_count' => count($this->usedComponents),
            'skipped_components' => $this->skippedComponents,
            'skipped_count' => count($this->skippedComponents),
            'scanned_files' => $this->scannedFiles,
            'raw_bytes' => $this->rawBytes,
            'minified_bytes' => $this->minifiedBytes,
            'gzip_bytes' => $this->gzipBytes,
            'full_bundle_min_bytes' => $this->fullBundleMinBytes,
            'tree_shake_savings_percent' => $this->treeShakeSavingsPercent,
            'output_path' => $this->outputPath,
        ];
    }
}
