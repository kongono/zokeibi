<?php

declare(strict_types=1);

namespace Zokeibi\Playground;

use InvalidArgumentException;
use Zokeibi\Zokeibi;
use Zokeibi\Studio\OklchContrast;
use Zokeibi\Studio\ThemeTokenConfig;
use Kongono\Http\Request;
use Kongono\Http\Response;
use Kongono\Http\Routing\Get;
use Kongono\Http\Routing\Post;
use Kongono\Http\Session\Session;
use Kongono\Security\TokenSigner;
use Kongono\View\ViewEngine;

/**
 * Central Visual Design Token & Theme Studio GUI (`/studio`).
 * Reads and writes Git-tracked `zokeibi.json` and live-compiles `zokeibi.min.css` in pure PHP 8.5.
 */
final readonly class StudioController
{
    public function __construct(
        private ViewEngine $views,
        private Zokeibi $zokeibi,
        private TokenSigner $signer,
        private string $studioProjectDir,
    ) {}

    #[Get('/studio', name: 'zokeibi.studio')]
    public function index(Request $request): Response
    {
        $config = $this->zokeibi->studio->load($this->studioProjectDir);
        $contrastChecks = OklchContrast::auditConfig($config);
        $accentHex = OklchContrast::oklchToHex($config->accentLightness, $config->chroma, $config->hue);
        $flash = $this->pullFlash($request);

        $assetsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets';
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
        $proof = $this->zokeibi->prover->prove($this->studioProjectDir, null, strict: true);

        $html = $this->views->render('pages/studio', [
            'activeNav' => 'studio',
            'assetVer' => $assetVer,
            'version' => $this->zokeibi->version(),
            'csrf' => $this->issueCsrf($request),
            'projectLabel' => basename($this->studioProjectDir) . '/zokeibi.json',
            'config' => $config,
            'accentHex' => $accentHex,
            'contrastChecks' => array_map(static fn ($c): array => $c->toArray(), $contrastChecks),
            'proof' => $proof,
            'rawJson' => $this->zokeibi->studio->readRawJson($this->studioProjectDir),
            'generatedCss' => $config->toCssLayer(),
            'w3cTokensJson' => $this->zokeibi->exporter->export($config, 'w3c-tokens'),
            'tailwindThemeCss' => $this->zokeibi->exporter->export($config, 'tailwind'),
            'flash' => $flash,
        ]);

        return Response::html($html);
    }

    #[Post('/studio/live-sync', name: 'zokeibi.studio.live_sync')]
    public function liveSync(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);

        $hue = $this->clampFloat($body['hue'] ?? $current->hue, 0.0, 360.0, $current->hue);
        $chroma = $this->clampFloat($body['chroma'] ?? $current->chroma, 0.0, 0.30, $current->chroma);
        $accentL = $this->clampFloat($body['accent_lightness'] ?? $current->accentLightness, 0.35, 0.82, $current->accentLightness);
        $mode = 'light' === ($body['mode'] ?? '') ? 'light' : 'dark';
        $radiusPx = $this->clampInt($body['radius_px'] ?? $current->radiusPx, 0, 24, $current->radiusPx);

        $fontScaleRaw = is_string($body['font_scale'] ?? null) ? $body['font_scale'] : $current->fontScale;
        $fontScale = array_key_exists($fontScaleRaw, ThemeTokenConfig::FONT_SIZES) ? $fontScaleRaw : $current->fontScale;

        $fontStackRaw = is_string($body['font_stack'] ?? null) ? $body['font_stack'] : $current->fontStack;
        $fontStack = array_key_exists($fontStackRaw, ThemeTokenConfig::FONT_STACKS) ? $fontStackRaw : $current->fontStack;

        $radiusPreset = match (true) {
            $radiusPx <= 6 => 'sharp',
            $radiusPx >= 16 => 'rounded',
            default => 'default',
        };

        $updated = new ThemeTokenConfig(
            activeTheme: 'studio',
            radiusPreset: $radiusPreset,
            hue: $hue,
            chroma: $chroma,
            accentLightness: $accentL,
            mode: $mode,
            radiusPx: $radiusPx,
            fontScale: $fontScale,
            fontStack: $fontStack,
            customColors: $current->customColors,
            customThemes: $current->customThemes,
            safelist: $current->safelist,
        );

        $report = $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);
        $proof = $this->zokeibi->prover->prove($this->studioProjectDir, null, strict: true);

        return Response::json([
            'ok' => true,
            'gzip_kb' => round($report->gzipBytes / 1024, 2),
            'components' => count($report->usedComponents),
            'css_version' => (string) time(),
            'proof' => $proof,
        ]);
    }

    #[Get('/studio/prove', name: 'zokeibi.studio.prove')]
    public function proveJson(): Response
    {
        $proof = $this->zokeibi->prover->prove($this->studioProjectDir, null, strict: true);

        return Response::json($proof, $proof['passed'] ? 200 : 422);
    }

    #[Post('/studio/save', name: 'zokeibi.studio.save')]
    public function save(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);

        $hue = $this->clampFloat($body['hue'] ?? $current->hue, 0.0, 360.0, $current->hue);
        $chroma = $this->clampFloat($body['chroma'] ?? $current->chroma, 0.0, 0.30, $current->chroma);
        $accentL = $this->clampFloat($body['accent_lightness'] ?? $current->accentLightness, 0.35, 0.82, $current->accentLightness);
        $mode = 'light' === ($body['mode'] ?? '') ? 'light' : 'dark';
        $radiusPx = $this->clampInt($body['radius_px'] ?? $current->radiusPx, 0, 24, $current->radiusPx);

        $fontScaleRaw = is_string($body['font_scale'] ?? null) ? $body['font_scale'] : $current->fontScale;
        $fontScale = array_key_exists($fontScaleRaw, ThemeTokenConfig::FONT_SIZES) ? $fontScaleRaw : $current->fontScale;

        $fontStackRaw = is_string($body['font_stack'] ?? null) ? $body['font_stack'] : $current->fontStack;
        $fontStack = array_key_exists($fontStackRaw, ThemeTokenConfig::FONT_STACKS) ? $fontStackRaw : $current->fontStack;

        $radiusPreset = match (true) {
            $radiusPx <= 6 => 'sharp',
            $radiusPx >= 16 => 'rounded',
            default => 'default',
        };

        $updated = new ThemeTokenConfig(
            activeTheme: 'studio',
            radiusPreset: $radiusPreset,
            hue: $hue,
            chroma: $chroma,
            accentLightness: $accentL,
            mode: $mode,
            radiusPx: $radiusPx,
            fontScale: $fontScale,
            fontStack: $fontStack,
            customColors: $current->customColors,
            customThemes: $current->customThemes,
            safelist: $current->safelist,
        );

        $report = $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);

        $this->setFlash(
            $request,
            'success',
            sprintf(
                'Saved tokens to zokeibi.json and recompiled zokeibi.min.css (%.1f KB gzipped · %d components).',
                $report->gzipBytes / 1024,
                count($report->usedComponents),
            ),
        );

        return Response::redirect('/studio', 303);
    }

    #[Post('/studio/themes/add', name: 'zokeibi.studio.themes.add')]
    public function addTheme(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);
        $rawName = is_string($body['theme_name'] ?? null) ? $body['theme_name'] : '';

        try {
            $slug = ThemeTokenConfig::assertValidTokenName($rawName);
            if (in_array($slug, ['sumi', 'washi', 'indigo', 'matcha', 'kintsugi', 'studio'], true)) {
                throw new InvalidArgumentException('Choose a custom name other than the built-in theme names.');
            }

            $hue = $this->clampFloat($body['hue'] ?? $current->hue, 0.0, 360.0, $current->hue);
            $chroma = $this->clampFloat($body['chroma'] ?? $current->chroma, 0.0, 0.30, $current->chroma);
            $accentL = $this->clampFloat($body['accent_lightness'] ?? $current->accentLightness, 0.35, 0.82, $current->accentLightness);
            $mode = 'light' === ($body['mode'] ?? '') ? 'light' : 'dark';
            $radiusPx = $this->clampInt($body['radius_px'] ?? $current->radiusPx, 0, 24, $current->radiusPx);

            $themes = $current->customThemes;
            $themes[$slug] = [
                'hue' => $hue,
                'chroma' => $chroma,
                'accent_lightness' => $accentL,
                'mode' => $mode,
                'radius_px' => $radiusPx,
            ];
            ksort($themes);

            $updated = new ThemeTokenConfig(
                activeTheme: $slug,
                radiusPreset: $current->radiusPreset,
                hue: $hue,
                chroma: $chroma,
                accentLightness: $accentL,
                mode: $mode,
                radiusPx: $radiusPx,
                fontScale: $current->fontScale,
                fontStack: $current->fontStack,
                customColors: $current->customColors,
                customThemes: $themes,
                safelist: $current->safelist,
            );

            $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);
            $this->setFlash($request, 'success', sprintf('Saved custom theme [data-zokeibi-theme="%s"] and rebuilt CSS.', $slug));
        } catch (InvalidArgumentException $e) {
            $this->setFlash($request, 'danger', $e->getMessage());
        }

        return Response::redirect('/studio', 303);
    }

    #[Post('/studio/themes/delete', name: 'zokeibi.studio.themes.delete')]
    public function deleteTheme(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);
        $slug = is_string($body['theme_name'] ?? null) ? strtolower(trim($body['theme_name'])) : '';

        if (isset($current->customThemes[$slug])) {
            $themes = $current->customThemes;
            unset($themes[$slug]);

            $updated = new ThemeTokenConfig(
                activeTheme: $slug === $current->activeTheme ? 'sumi' : $current->activeTheme,
                radiusPreset: $current->radiusPreset,
                hue: $current->hue,
                chroma: $current->chroma,
                accentLightness: $current->accentLightness,
                mode: $current->mode,
                radiusPx: $current->radiusPx,
                fontScale: $current->fontScale,
                fontStack: $current->fontStack,
                customColors: $current->customColors,
                customThemes: $themes,
                safelist: $current->safelist,
            );

            $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);
            $this->setFlash($request, 'info', sprintf('Removed custom theme "%s" and rebuilt CSS.', $slug));
        }

        return Response::redirect('/studio', 303);
    }

    #[Post('/studio/colors/add', name: 'zokeibi.studio.colors.add')]
    public function addColor(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);
        $rawName = is_string($body['color_name'] ?? null) ? $body['color_name'] : '';
        $rawValue = is_string($body['color_value'] ?? null) ? $body['color_value'] : '';

        try {
            $name = ThemeTokenConfig::assertValidTokenName($rawName);
            $value = ThemeTokenConfig::assertValidColorValue($rawValue);

            $colors = $current->customColors;
            $colors[$name] = $value;
            ksort($colors);

            $updated = new ThemeTokenConfig(
                activeTheme: $current->activeTheme,
                radiusPreset: $current->radiusPreset,
                hue: $current->hue,
                chroma: $current->chroma,
                accentLightness: $current->accentLightness,
                mode: $current->mode,
                radiusPx: $current->radiusPx,
                fontScale: $current->fontScale,
                fontStack: $current->fontStack,
                customColors: $colors,
                customThemes: $current->customThemes,
                safelist: $current->safelist,
            );

            $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);
            $this->setFlash($request, 'success', sprintf('Added custom CSS token --z-color-%s (%s) and rebuilt CSS.', $name, $value));
        } catch (InvalidArgumentException $e) {
            $this->setFlash($request, 'danger', $e->getMessage());
        }

        return Response::redirect('/studio', 303);
    }

    #[Post('/studio/colors/delete', name: 'zokeibi.studio.colors.delete')]
    public function deleteColor(Request $request): Response
    {
        $current = $this->zokeibi->studio->load($this->studioProjectDir);
        $body = $this->formParams($request);
        $name = is_string($body['color_name'] ?? null) ? strtolower(trim($body['color_name'])) : '';

        if (isset($current->customColors[$name])) {
            $colors = $current->customColors;
            unset($colors[$name]);

            $updated = new ThemeTokenConfig(
                activeTheme: $current->activeTheme,
                radiusPreset: $current->radiusPreset,
                hue: $current->hue,
                chroma: $current->chroma,
                accentLightness: $current->accentLightness,
                mode: $current->mode,
                radiusPx: $current->radiusPx,
                fontScale: $current->fontScale,
                fontStack: $current->fontStack,
                customColors: $colors,
                customThemes: $current->customThemes,
                safelist: $current->safelist,
            );

            $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $updated, $this->zokeibi->compiler);
            $this->setFlash($request, 'info', sprintf('Removed custom CSS token --z-color-%s and rebuilt CSS.', $name));
        }

        return Response::redirect('/studio', 303);
    }

    #[Post('/studio/reset', name: 'zokeibi.studio.reset')]
    public function reset(Request $request): Response
    {
        $defaults = ThemeTokenConfig::defaults();
        $this->zokeibi->studio->saveAndBuild($this->studioProjectDir, $defaults, $this->zokeibi->compiler);
        $this->setFlash($request, 'info', 'Reset Studio tokens to default Sumi Crimson and rebuilt zokeibi.min.css.');

        return Response::redirect('/studio', 303);
    }

    #[Get('/studio/tokens.css', name: 'zokeibi.studio.css')]
    public function exportCss(): Response
    {
        $config = $this->zokeibi->studio->load($this->studioProjectDir);

        return Response::text($config->toCssLayer(), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[Get('/studio/export', name: 'zokeibi.studio.export')]
    public function exportFormat(Request $request): Response
    {
        $query = $request->getQueryParams();
        $rawFormat = is_string($query['format'] ?? null) ? $query['format'] : 'css';
        $format = in_array(strtolower(trim($rawFormat)), ['css', 'w3c-tokens', 'tailwind'], true)
            ? strtolower(trim($rawFormat))
            : 'css';

        $config = $this->zokeibi->studio->load($this->studioProjectDir);
        $payload = $this->zokeibi->exporter->export($config, $format);

        return Response::text($payload, 200, [
            'Content-Type' => $this->zokeibi->exporter->contentType($format),
            'Content-Disposition' => 'inline; filename="' . $this->zokeibi->exporter->filename($format) . '"',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formParams(Request $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function issueCsrf(Request $request): string
    {
        $session = $request->getAttribute(Session::class);
        if ($session instanceof Session) {
            $session->set('zokeibi_studio_active', true);

            return $this->signer->generateCsrfToken($session->id());
        }

        return $this->signer->generateCsrfToken('zokeibi-studio-guest');
    }

    private function setFlash(Request $request, string $variant, string $message): void
    {
        $session = $request->getAttribute(Session::class);
        if ($session instanceof Session) {
            $session->set('studio_flash', ['variant' => $variant, 'message' => $message]);
        }
    }

    /**
     * @return array{variant: string, message: string}|null
     */
    private function pullFlash(Request $request): ?array
    {
        $session = $request->getAttribute(Session::class);
        if (!$session instanceof Session) {
            return null;
        }
        $flash = $session->get('studio_flash');
        if (!is_array($flash) || !isset($flash['variant'], $flash['message']) || !is_string($flash['variant']) || !is_string($flash['message'])) {
            return null;
        }
        $session->remove('studio_flash');

        return ['variant' => $flash['variant'], 'message' => $flash['message']];
    }

    private function clampFloat(mixed $raw, float $min, float $max, float $default): float
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        return round(max($min, min($max, (float) $raw)), 3);
    }

    private function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) round((float) $raw)));
    }
}
