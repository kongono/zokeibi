<?php

declare(strict_types=1);

namespace Zokeibi\Audit;

/**
 * Immutable UI schema, accessibility, and strict-CSP audit report.
 */
final readonly class AuditReport
{
    /**
     * @param list<AuditFinding> $findings
     * @param array<string, int> $componentUsage
     */
    public function __construct(
        public int $score,
        public bool $passed,
        public int $scannedFiles,
        public int $errorCount,
        public int $warningCount,
        public array $componentUsage,
        public array $findings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'passed' => $this->passed,
            'scanned_files' => $this->scannedFiles,
            'error_count' => $this->errorCount,
            'warning_count' => $this->warningCount,
            'component_usage' => $this->componentUsage,
            'findings' => array_map(static fn (AuditFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
