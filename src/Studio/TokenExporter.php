<?php

declare(strict_types=1);

namespace Zokeibi\Studio;

use InvalidArgumentException;

/**
 * Exports Git-tracked Zokeibi Studio tokens (`zokeibi.json`) to universal frontend formats:
 *   - `css`:        Standard `@layer zokeibi.themes` CSS variables
 *   - `w3c-tokens`: W3C Design Tokens Community Group (DTCG) JSON for Figma / Style Dictionary / React
 *   - `tailwind`:   Tailwind CSS v4 `@theme` bridge so Tailwind apps share Zokeibi's OKLCH tokens
 */
final readonly class TokenExporter
{
    /** @var list<string> */
    public const array FORMATS = ['css', 'w3c-tokens', 'tailwind'];

    public function export(ThemeTokenConfig $config, string $format): string
    {
        return match (strtolower(trim($format))) {
            'css' => $config->toCssLayer(),
            'w3c-tokens', 'w3c', 'dtcg', 'json' => $this->toW3cDesignTokens($config),
            'tailwind', 'tailwind-v4' => $this->toTailwindTheme($config),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported export format "%s". Allowed: %s.',
                $format,
                implode(', ', self::FORMATS),
            )),
        };
    }

    public function contentType(string $format): string
    {
        return match (strtolower(trim($format))) {
            'w3c-tokens', 'w3c', 'dtcg', 'json' => 'application/json; charset=utf-8',
            default => 'text/css; charset=utf-8',
        };
    }

    public function filename(string $format): string
    {
        return match (strtolower(trim($format))) {
            'w3c-tokens', 'w3c', 'dtcg', 'json' => 'zokeibi.tokens.json',
            'tailwind', 'tailwind-v4' => 'zokeibi.tailwind.css',
            default => 'zokeibi.tokens.css',
        };
    }

    private function toW3cDesignTokens(ThemeTokenConfig $config): string
    {
        $accentOklch = sprintf('oklch(%.3f %.3f %.1f)', $config->accentLightness, $config->chroma, $config->hue);
        $accentHex = OklchContrast::oklchToHex($config->accentLightness, $config->chroma, $config->hue);
        $inkL = OklchContrast::recommendedAccentInkLightness($config->accentLightness, $config->chroma, $config->hue);
        $inkHex = OklchContrast::oklchToHex($inkL, $inkL < 0.5 ? 0.018 : 0.008, $config->hue);

        $customColors = [];
        foreach ($config->customColors as $name => $val) {
            $customColors[$name] = [
                '$value' => $val,
                '$type' => 'color',
                '$description' => 'Zokeibi custom token --z-color-' . $name,
            ];
        }

        $themes = [];
        foreach ($config->customThemes as $themeName => $def) {
            $themes[$themeName] = [
                'accent' => [
                    '$value' => sprintf('oklch(%.3f %.3f %.1f)', $def['accent_lightness'], $def['chroma'], $def['hue']),
                    '$type' => 'color',
                ],
                'accentHex' => [
                    '$value' => OklchContrast::oklchToHex($def['accent_lightness'], $def['chroma'], $def['hue']),
                    '$type' => 'color',
                ],
                'radius' => [
                    '$value' => $def['radius_px'] . 'px',
                    '$type' => 'dimension',
                ],
                'mode' => [
                    '$value' => $def['mode'],
                    '$type' => 'other',
                ],
            ];
        }

        $payload = [
            '$schema' => 'https://tr.designtokens.org/format/',
            'zokeibi' => [
                'brand' => [
                    'accent' => ['$value' => $accentOklch, '$type' => 'color', '$description' => 'Primary perceptual OKLCH brand accent'],
                    'accentHex' => ['$value' => $accentHex, '$type' => 'color', '$description' => 'sRGB hex equivalent'],
                    'accentInk' => ['$value' => $inkHex, '$type' => 'color', '$description' => 'Auto-contrasted button foreground ink'],
                    'hue' => ['$value' => $config->hue, '$type' => 'number'],
                    'chroma' => ['$value' => $config->chroma, '$type' => 'number'],
                ],
                'geometry' => [
                    'radius' => ['$value' => $config->radiusPx . 'px', '$type' => 'dimension'],
                    'radiusSm' => ['$value' => max(0, (int) round($config->radiusPx * 0.66)) . 'px', '$type' => 'dimension'],
                ],
                'typography' => [
                    'fontFamily' => ['$value' => ThemeTokenConfig::FONT_STACKS[$config->fontStack] ?? ThemeTokenConfig::FONT_STACKS['system'], '$type' => 'fontFamily'],
                    'fontSize' => ['$value' => ThemeTokenConfig::FONT_SIZES[$config->fontScale] ?? ThemeTokenConfig::FONT_SIZES['default'], '$type' => 'dimension'],
                ],
                'customColors' => $customColors,
                'customThemes' => $themes,
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function toTailwindTheme(ThemeTokenConfig $config): string
    {
        $accentOklch = sprintf('oklch(%.3f %.3f %.1f)', $config->accentLightness, $config->chroma, $config->hue);
        $accentStrong = sprintf('oklch(%.3f %.3f %.1f)', max(0.28, round($config->accentLightness - 0.11, 3)), $config->chroma, $config->hue);
        $inkL = OklchContrast::recommendedAccentInkLightness($config->accentLightness, $config->chroma, $config->hue);
        $inkOklch = sprintf('oklch(%.3f %.3f %.1f)', $inkL, $inkL < 0.5 ? 0.018 : 0.008, $config->hue);
        $radSm = max(0, (int) round($config->radiusPx * 0.66));

        $lines = [
            '/* Zokeibi (造形美) -> Tailwind CSS v4 @theme bridge (generated from zokeibi.json) */',
            '@theme {',
            sprintf('  --color-brand: %s;', $accentOklch),
            sprintf('  --color-brand-strong: %s;', $accentStrong),
            sprintf('  --color-brand-ink: %s;', $inkOklch),
            sprintf('  --radius-zokeibi: %dpx;', $config->radiusPx),
            sprintf('  --radius-zokeibi-sm: %dpx;', $radSm),
            sprintf('  --font-zokeibi: %s;', ThemeTokenConfig::FONT_STACKS[$config->fontStack] ?? ThemeTokenConfig::FONT_STACKS['system']),
        ];

        foreach ($config->customColors as $name => $val) {
            if (!ThemeTokenConfig::isValidTokenName($name) || !ThemeTokenConfig::isValidColorValue($val)) {
                continue;
            }
            $lines[] = sprintf('  --color-%s: %s;', strtolower(trim($name)), trim($val));
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }
}
