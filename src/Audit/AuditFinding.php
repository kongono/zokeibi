<?php

declare(strict_types=1);

namespace Zokeibi\Audit;

/**
 * Single static UI schema, strict-CSP, or accessibility finding with plain-English 5W1H guidance
 * for both human developers and AI agents.
 */
final readonly class AuditFinding
{
    /**
     * @param list<string> $didYouMean
     */
    public function __construct(
        public string $rule,
        public string $severity,
        public string $file,
        public int $line,
        public string $message,
        public string $hint = '',
        public string $why = '',
        public array $didYouMean = [],
    ) {}

    /**
     * @return array{
     *     rule: string,
     *     severity: string,
     *     file: string,
     *     line: int,
     *     where: string,
     *     what: string,
     *     why: string,
     *     how_to_fix: string,
     *     did_you_mean: list<string>,
     *     message: string,
     *     hint: string
     * }
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'file' => $this->file,
            'line' => $this->line,
            'where' => sprintf('%s:%d', $this->file, $this->line),
            'what' => $this->message,
            'why' => '' !== $this->why ? $this->why : $this->message,
            'how_to_fix' => $this->hint,
            'did_you_mean' => $this->didYouMean,
            'message' => $this->message,
            'hint' => $this->hint,
        ];
    }
}
