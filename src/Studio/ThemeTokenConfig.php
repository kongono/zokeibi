<?php

declare(strict_types=1);

namespace Zokeibi\Studio;

use InvalidArgumentException;

/**
 * Git-tracked Zokeibi Studio design token configuration stored inside `zokeibi.json`.
 *
 * @phpstan-type CustomThemeDef array{
 *     hue: float,
 *     chroma: float,
 *     accent_lightness: float,
 *     mode: 'dark'|'light',
 *     radius_px: int
 * }
 */
final readonly class ThemeTokenConfig
{
    public const array FONT_STACKS = [
        'system' => 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        'editorial' => 'Iowan Old Style, Palatino Linotype, "Book Antiqua", Georgia, ui-serif, serif',
        'geometric' => '"Segoe UI Variable", "Avenir Next", Avenir, "Helvetica Neue", ui-sans-serif, sans-serif',
        'mono' => '"JetBrains Mono", "Cascadia Code", "Fira Code", ui-monospace, SFMono-Regular, Menlo, monospace',
    ];

    public const array FONT_SIZES = [
        'compact' => '13px',
        'default' => '14px',
        'comfortable' => '15px',
    ];

    /**
     * @param 'dark'|'light'                $mode
     * @param array<string, string>         $customColors
     * @param array<string, CustomThemeDef> $customThemes
     * @param list<string>                  $safelist
     */
    public function __construct(
        public string $activeTheme = 'sumi',
        public string $radiusPreset = 'default',
        public float $hue = 355.0,
        public float $chroma = 0.145,
        public float $accentLightness = 0.65,
        public string $mode = 'dark',
        public int $radiusPx = 12,
        public string $fontScale = 'default',
        public string $fontStack = 'system',
        public array $customColors = [],
        public array $customThemes = [],
        public array $safelist = [],
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Hydrates and strictly validates a ThemeTokenConfig from decoded `zokeibi.json` data.
     *
     * @param array<mixed, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $studio = isset($data['studio']) && is_array($data['studio']) ? $data['studio'] : $data;

        $activeTheme = isset($studio['active_theme']) && is_string($studio['active_theme'])
            ? self::sanitizeSlug($studio['active_theme'], 'sumi')
            : (isset($data['theme']) && is_string($data['theme']) ? self::sanitizeSlug($data['theme'], 'sumi') : 'sumi');

        $radiusPreset = isset($studio['radius_preset']) && is_string($studio['radius_preset']) && in_array($studio['radius_preset'], ['sharp', 'default', 'rounded'], true)
            ? $studio['radius_preset']
            : (isset($data['radius']) && is_string($data['radius']) && in_array($data['radius'], ['sharp', 'default', 'rounded'], true) ? $data['radius'] : 'default');

        $hue = self::clampFloat($studio['hue'] ?? 355.0, 0.0, 360.0, 355.0);
        $chroma = self::clampFloat($studio['chroma'] ?? 0.145, 0.0, 0.30, 0.145);
        $accentL = self::clampFloat($studio['accent_lightness'] ?? 0.65, 0.35, 0.82, 0.65);
        $mode = isset($studio['mode']) && 'light' === $studio['mode'] ? 'light' : 'dark';
        $radiusPx = self::clampInt($studio['radius_px'] ?? 12, 0, 24, 12);

        $fontScale = isset($studio['font_scale']) && is_string($studio['font_scale']) && array_key_exists($studio['font_scale'], self::FONT_SIZES)
            ? $studio['font_scale']
            : 'default';

        $fontStack = isset($studio['font_stack']) && is_string($studio['font_stack']) && array_key_exists($studio['font_stack'], self::FONT_STACKS)
            ? $studio['font_stack']
            : 'system';

        $customColors = [];
        if (isset($studio['custom_colors']) && is_array($studio['custom_colors'])) {
            foreach ($studio['custom_colors'] as $name => $value) {
                if (is_string($name) && is_string($value) && self::isValidTokenName($name) && self::isValidColorValue($value)) {
                    $customColors[strtolower(trim($name))] = trim($value);
                }
            }
            ksort($customColors);
        }

        $customThemes = [];
        if (isset($studio['custom_themes']) && is_array($studio['custom_themes'])) {
            foreach ($studio['custom_themes'] as $name => $rawTheme) {
                if (!is_string($name) || !is_array($rawTheme) || !self::isValidTokenName($name)) {
                    continue;
                }
                $slug = strtolower(trim($name));
                if (in_array($slug, ['sumi', 'washi', 'indigo', 'matcha', 'kintsugi', 'studio'], true)) {
                    continue;
                }
                $customThemes[$slug] = [
                    'hue' => self::clampFloat($rawTheme['hue'] ?? 355.0, 0.0, 360.0, 355.0),
                    'chroma' => self::clampFloat($rawTheme['chroma'] ?? 0.145, 0.0, 0.30, 0.145),
                    'accent_lightness' => self::clampFloat($rawTheme['accent_lightness'] ?? 0.65, 0.35, 0.82, 0.65),
                    'mode' => isset($rawTheme['mode']) && 'light' === $rawTheme['mode'] ? 'light' : 'dark',
                    'radius_px' => self::clampInt($rawTheme['radius_px'] ?? 12, 0, 24, 12),
                ];
            }
            ksort($customThemes);
        }

        $safelist = [];
        $rawSafelist = $studio['safelist'] ?? ($data['safelist'] ?? []);
        if (is_array($rawSafelist)) {
            $seen = [];
            foreach ($rawSafelist as $item) {
                if (is_string($item) && self::isValidTokenName($item)) {
                    $seen[strtolower(trim($item))] = true;
                }
            }
            $safelist = array_keys($seen);
            sort($safelist);
        }

        return new self(
            activeTheme: $activeTheme,
            radiusPreset: $radiusPreset,
            hue: $hue,
            chroma: $chroma,
            accentLightness: $accentL,
            mode: $mode,
            radiusPx: $radiusPx,
            fontScale: $fontScale,
            fontStack: $fontStack,
            customColors: $customColors,
            customThemes: $customThemes,
            safelist: $safelist,
        );
    }

    public static function isValidTokenName(string $name): bool
    {
        return 1 === preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', strtolower(trim($name)));
    }

    /**
     * Strictly allows only #hex (3/6/8 digits) or modern oklch(...) values to prevent CSS injection.
     */
    public static function isValidColorValue(string $value): bool
    {
        $v = trim($value);
        if (1 === preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\z/', $v)) {
            return true;
        }

        $num = '(?:0?\.\d+|\d+(?:\.\d+)?)';

        return 1 === preg_match('/\Aoklch\(\s*' . $num . '%?\s+' . $num . '\s+' . $num . '(?:\s*\/\s*' . $num . '%?)?\s*\)\z/', $v);
    }

    public static function assertValidTokenName(string $name): string
    {
        $clean = strtolower(trim($name));
        if (!self::isValidTokenName($clean)) {
            throw new InvalidArgumentException('Token name must be 2-32 lowercase letters, digits, or hyphens.');
        }

        return $clean;
    }

    public static function assertValidColorValue(string $value): string
    {
        $clean = trim($value);
        if (!self::isValidColorValue($clean)) {
            throw new InvalidArgumentException('Color value must be a valid #hex or oklch(...) expression.');
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active_theme' => $this->activeTheme,
            'radius_preset' => $this->radiusPreset,
            'hue' => $this->hue,
            'chroma' => $this->chroma,
            'accent_lightness' => $this->accentLightness,
            'mode' => $this->mode,
            'radius_px' => $this->radiusPx,
            'font_scale' => $this->fontScale,
            'font_stack' => $this->fontStack,
            'custom_colors' => $this->customColors,
            'custom_themes' => $this->customThemes,
            'safelist' => $this->safelist,
        ];
    }

    /**
     * Compiles Studio custom tokens & custom named themes into a safe `@layer zokeibi.themes` CSS block.
     */
    public function toCssLayer(): string
    {
        $lines = [];
        $lines[] = '@layer zokeibi.themes {';

        // 1. Global Studio overrides (:root custom colors, font stack, and active custom tokens)
        $rootDecls = [];
        $fontStackCss = self::FONT_STACKS[$this->fontStack] ?? self::FONT_STACKS['system'];
        $fontSizeCss = self::FONT_SIZES[$this->fontScale] ?? self::FONT_SIZES['default'];
        if ('system' !== $this->fontStack && array_key_exists($this->fontStack, self::FONT_STACKS)) {
            $rootDecls[] = sprintf('    --z-sans: %s;', $fontStackCss);
        }
        if ('default' !== $this->fontScale && array_key_exists($this->fontScale, self::FONT_SIZES)) {
            $rootDecls[] = sprintf('    --z-font-size: %s;', $fontSizeCss);
        }
        foreach ($this->customColors as $tokenName => $colorValue) {
            if (!self::isValidTokenName($tokenName) || !self::isValidColorValue($colorValue)) {
                continue;
            }
            $rootDecls[] = sprintf('    --z-color-%s: %s;', strtolower(trim($tokenName)), trim($colorValue));
        }

        // Emit explicit `[data-zokeibi-theme="studio"]` matching the active Studio knobs
        $studioDecls = $this->buildThemeDeclarations(
            hue: self::clampFloat($this->hue, 0.0, 360.0, 355.0),
            chroma: self::clampFloat($this->chroma, 0.0, 0.30, 0.145),
            accentLightness: self::clampFloat($this->accentLightness, 0.35, 0.82, 0.65),
            mode: 'light' === $this->mode ? 'light' : 'dark',
            radiusPx: self::clampInt($this->radiusPx, 0, 24, 12),
        );

        if ([] !== $rootDecls) {
            $lines[] = '  :root {';
            foreach ($rootDecls as $decl) {
                $lines[] = $decl;
            }
            $lines[] = '  }';
        }

        if ('default' !== $this->fontScale && array_key_exists($this->fontScale, self::FONT_SIZES)) {
            $lines[] = sprintf('  html, body { font-size: %s; }', $fontSizeCss);
        }

        $lines[] = '  [data-zokeibi-theme="studio"] {';
        foreach ($studioDecls as $decl) {
            $lines[] = $decl;
        }
        $lines[] = '  }';

        // 2. Emit every user-saved custom named theme (`[data-zokeibi-theme="<name>"]`)
        foreach ($this->customThemes as $themeName => $def) {
            if (!self::isValidTokenName($themeName)) {
                continue;
            }
            $cleanTheme = strtolower(trim($themeName));
            if (in_array($cleanTheme, ['sumi', 'washi', 'indigo', 'matcha', 'kintsugi', 'studio'], true)) {
                continue;
            }
            $lines[] = sprintf('  [data-zokeibi-theme="%s"] {', $cleanTheme);
            foreach (
                $this->buildThemeDeclarations(
                    hue: self::clampFloat($def['hue'], 0.0, 360.0, 355.0),
                    chroma: self::clampFloat($def['chroma'], 0.0, 0.30, 0.145),
                    accentLightness: self::clampFloat($def['accent_lightness'], 0.35, 0.82, 0.65),
                    mode: 'light' === $def['mode'] ? 'light' : 'dark',
                    radiusPx: self::clampInt($def['radius_px'], 0, 24, 12),
                ) as $decl
            ) {
                $lines[] = $decl;
            }
            $lines[] = '  }';
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param 'dark'|'light' $mode
     * @return list<string>
     */
    private function buildThemeDeclarations(
        float $hue,
        float $chroma,
        float $accentLightness,
        string $mode,
        int $radiusPx,
    ): array {
        $radSm = max(0, (int) round($radiusPx * 0.66));
        $accentStrong = max(0.28, round($accentLightness - 0.11, 3));
        $inkL = OklchContrast::recommendedAccentInkLightness($accentLightness, $chroma, $hue);
        $inkC = $inkL < 0.5 ? 0.018 : 0.008;

        $decls = [
            sprintf('    --z-hue: %.1f;', $hue),
            sprintf('    --z-chroma: %.3f;', $chroma),
            sprintf('    --z-radius: %dpx;', $radiusPx),
            sprintf('    --z-radius-sm: %dpx;', $radSm),
            sprintf('    --z-accent: oklch(%.3f var(--z-chroma) var(--z-hue));', $accentLightness),
            sprintf('    --z-accent-strong: oklch(%.3f var(--z-chroma) var(--z-hue));', $accentStrong),
            sprintf('    --z-accent-ink: oklch(%.3f %.3f var(--z-hue));', $inkL, $inkC),
        ];

        if ('light' === $mode) {
            $decls[] = '    color-scheme: light;';
            $decls[] = '    --z-bg: oklch(0.972 0.004 95);';
            $decls[] = '    --z-surface: oklch(0.998 0.002 95);';
            $decls[] = '    --z-surface-2: oklch(0.948 0.006 264);';
            $decls[] = '    --z-surface-hover: oklch(0.918 0.008 264);';
            $decls[] = '    --z-border: oklch(0.885 0.01 264);';
            $decls[] = '    --z-border-strong: oklch(0.795 0.014 264);';
            $decls[] = '    --z-ink: oklch(0.18 0.014 264);';
            $decls[] = '    --z-ink-muted: oklch(0.43 0.015 264);';
            $decls[] = '    --z-ink-soft: oklch(0.47 0.014 264);';
            $decls[] = '    --z-emerald: oklch(0.46 0.14 162);';
            $decls[] = '    --z-emerald-ink: oklch(0.985 0.008 162);';
            $decls[] = '    --z-amber: oklch(0.49 0.145 75);';
            $decls[] = '    --z-danger: oklch(0.50 0.185 20);';
            $decls[] = '    --z-blue: oklch(0.48 0.155 256);';
            $decls[] = '    --z-shadow: 0 12px 32px oklch(0.2 0.02 264 / 0.1);';
        } else {
            $decls[] = '    color-scheme: dark;';
            $decls[] = sprintf('    --z-bg: oklch(0.152 0.014 %.1f);', $hue);
            $decls[] = sprintf('    --z-surface: oklch(0.190 0.016 %.1f);', $hue);
            $decls[] = sprintf('    --z-surface-2: oklch(0.228 0.018 %.1f);', $hue);
            $decls[] = sprintf('    --z-surface-hover: oklch(0.262 0.020 %.1f);', $hue);
            $decls[] = sprintf('    --z-border: oklch(0.296 0.020 %.1f);', $hue);
            $decls[] = sprintf('    --z-border-strong: oklch(0.372 0.024 %.1f);', $hue);
            $decls[] = '    --z-ink: oklch(0.955 0.006 264);';
            $decls[] = '    --z-ink-muted: oklch(0.725 0.014 264);';
            $decls[] = '    --z-ink-soft: oklch(0.645 0.014 264);';
            $decls[] = '    --z-emerald: oklch(0.72 0.155 162);';
            $decls[] = '    --z-emerald-ink: oklch(0.15 0.02 162);';
            $decls[] = '    --z-amber: oklch(0.80 0.15 78);';
            $decls[] = '    --z-danger: oklch(0.66 0.195 22);';
            $decls[] = '    --z-blue: oklch(0.71 0.15 254);';
            $decls[] = '    --z-shadow: 0 18px 46px oklch(0.05 0.01 264 / 0.45);';
        }

        return $decls;
    }

    private static function sanitizeSlug(string $raw, string $fallback): string
    {
        $slug = strtolower(trim($raw));

        return self::isValidTokenName($slug) ? $slug : $fallback;
    }

    private static function clampFloat(mixed $raw, float $min, float $max, float $default): float
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        return round(max($min, min($max, (float) $raw)), 3);
    }

    private static function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) round((float) $raw)));
    }
}
