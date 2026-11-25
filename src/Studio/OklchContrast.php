<?php

declare(strict_types=1);

namespace Zokeibi\Studio;

/**
 * Pure PHP 8.5 perceptual OKLCH -> sRGB -> WCAG 2.1 contrast ratio engine.
 * Implements Bjorn Ottosson's OKLab/OKLCH to linear sRGB matrix with gamut clamping.
 */
final readonly class OklchContrast
{
    /**
     * Converts OKLCH (L in 0..1, C >= 0, H in degrees 0..360) to clamped 8-bit sRGB channels.
     *
     * @return array{r: int, g: int, b: int}
     */
    public static function oklchToRgb(float $lightness, float $chroma, float $hueDegrees): array
    {
        $rad = deg2rad($hueDegrees);
        $a = $chroma * cos($rad);
        $b = $chroma * sin($rad);

        $l_ = $lightness + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $lightness - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $lightness - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $l_ * $l_ * $l_;
        $m = $m_ * $m_ * $m_;
        $s = $s_ * $s_ * $s_;

        $linR = +4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
        $linG = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
        $linB = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;

        return [
            'r' => self::linearToSrgbByte($linR),
            'g' => self::linearToSrgbByte($linG),
            'b' => self::linearToSrgbByte($linB),
        ];
    }

    public static function oklchToHex(float $lightness, float $chroma, float $hueDegrees): string
    {
        $rgb = self::oklchToRgb($lightness, $chroma, $hueDegrees);

        return sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    /**
     * Computes W3C WCAG 2.1 relative luminance (0.0..1.0) for an OKLCH color.
     */
    public static function relativeLuminance(float $lightness, float $chroma, float $hueDegrees): float
    {
        $rgb = self::oklchToRgb($lightness, $chroma, $hueDegrees);
        $r = self::srgbByteToLinear($rgb['r']);
        $g = self::srgbByteToLinear($rgb['g']);
        $b = self::srgbByteToLinear($rgb['b']);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Computes WCAG 2.1 contrast ratio (1.0..21.0) between two OKLCH colors.
     */
    public static function contrastRatio(
        float $l1,
        float $c1,
        float $h1,
        float $l2,
        float $c2,
        float $h2,
    ): float {
        $lum1 = self::relativeLuminance($l1, $c1, $h1);
        $lum2 = self::relativeLuminance($l2, $c2, $h2);
        $lighter = max($lum1, $lum2);
        $darker = min($lum1, $lum2);

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    /**
     * Chooses optimal dark or light ink lightness for an accent color so buttons stay legible.
     */
    public static function recommendedAccentInkLightness(float $accentL, float $accentC, float $accentH): float
    {
        $lightRatio = self::contrastRatio(0.985, 0.008, $accentH, $accentL, $accentC, $accentH);
        $darkRatio = self::contrastRatio(0.145, 0.018, $accentH, $accentL, $accentC, $accentH);

        return $darkRatio > $lightRatio ? 0.145 : 0.985;
    }

    /**
     * Evaluates the four core WCAG 2.1 contrast pairs for a Studio token configuration.
     *
     * @return list<ContrastCheck>
     */
    public static function auditConfig(ThemeTokenConfig $config): array
    {
        $isLight = 'light' === $config->mode;
        $surfaceHue = $isLight ? 95.0 : $config->hue;
        $bgChroma = $isLight ? 0.004 : 0.014;
        $surfChroma = $isLight ? 0.002 : 0.016;

        $bgL = $isLight ? 0.972 : 0.152;
        $surfL = $isLight ? 0.998 : 0.190;
        $inkL = $isLight ? 0.180 : 0.955;
        $mutedL = $isLight ? 0.430 : 0.725;
        $inkC = $isLight ? 0.014 : 0.006;

        $accentInkL = self::recommendedAccentInkLightness($config->accentLightness, $config->chroma, $config->hue);
        $accentInkC = $accentInkL < 0.5 ? 0.018 : 0.008;

        return [
            self::makeCheck(
                'accent_button',
                'Primary Button (Ink on Accent)',
                $accentInkL,
                $accentInkC,
                $config->hue,
                $config->accentLightness,
                $config->chroma,
                $config->hue,
            ),
            self::makeCheck(
                'body_text',
                'Body Copy (Ink on Background)',
                $inkL,
                $inkC,
                264.0,
                $bgL,
                $bgChroma,
                $surfaceHue,
            ),
            self::makeCheck(
                'card_text',
                'Card Heading (Ink on Surface)',
                $inkL,
                $inkC,
                264.0,
                $surfL,
                $surfChroma,
                $surfaceHue,
            ),
            self::makeCheck(
                'muted_text',
                'Muted Caption (Muted on Surface)',
                $mutedL,
                0.014,
                264.0,
                $surfL,
                $surfChroma,
                $surfaceHue,
            ),
        ];
    }

    private static function makeCheck(
        string $id,
        string $label,
        float $fgL,
        float $fgC,
        float $fgH,
        float $bgL,
        float $bgC,
        float $bgH,
    ): ContrastCheck {
        $ratio = self::contrastRatio($fgL, $fgC, $fgH, $bgL, $bgC, $bgH);
        $grade = match (true) {
            $ratio >= 7.0 => 'AAA',
            $ratio >= 4.5 => 'AA',
            $ratio >= 3.0 => 'AA Large',
            default => 'FAIL',
        };

        return new ContrastCheck(
            id: $id,
            label: $label,
            foregroundHex: self::oklchToHex($fgL, $fgC, $fgH),
            backgroundHex: self::oklchToHex($bgL, $bgC, $bgH),
            ratio: $ratio,
            grade: $grade,
            passesAa: $ratio >= 4.5,
        );
    }

    private static function linearToSrgbByte(float $linear): int
    {
        $clamped = max(0.0, min(1.0, $linear));
        $gamma = $clamped <= 0.0031308
            ? 12.92 * $clamped
            : 1.055 * ($clamped ** (1.0 / 2.4)) - 0.055;

        return (int) round(max(0.0, min(1.0, $gamma)) * 255.0);
    }

    private static function srgbByteToLinear(int $byte): float
    {
        $c = $byte / 255.0;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
