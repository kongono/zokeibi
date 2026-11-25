<?php

declare(strict_types=1);

namespace Zokeibi\Installer;

/**
 * Result of a Zokeibi `init`, `add`, or `eject` operation.
 */
final readonly class InstallReport
{
    /**
     * @param list<string> $written
     * @param list<string> $skipped
     */
    public function __construct(
        public string $action,
        public string $projectDir,
        public array $written,
        public array $skipped,
    ) {}

    /**
     * @return array{action: string, project_dir: string, written: list<string>, skipped: list<string>}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'project_dir' => $this->projectDir,
            'written' => $this->written,
            'skipped' => $this->skipped,
        ];
    }
}
