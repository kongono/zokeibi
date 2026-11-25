<?php

declare(strict_types=1);

namespace Zokeibi\Studio;

/**
 * Single WCAG 2.1 contrast measurement between a foreground and background OKLCH pair.
 */
final readonly class ContrastCheck
{
    public function __construct(
        public string $id,
        public string $label,
        public string $foregroundHex,
        public string $backgroundHex,
        public float $ratio,
        public string $grade,
        public bool $passesAa,
    ) {}

    /**
     * @return array{id: string, label: string, foreground_hex: string, background_hex: string, ratio: float, grade: string, passes_aa: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'foreground_hex' => $this->foregroundHex,
            'background_hex' => $this->backgroundHex,
            'ratio' => $this->ratio,
            'grade' => $this->grade,
            'passes_aa' => $this->passesAa,
        ];
    }
}
