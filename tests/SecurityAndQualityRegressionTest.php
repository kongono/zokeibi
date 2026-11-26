<?php

declare(strict_types=1);

namespace Zokeibi\Tests;

use InvalidArgumentException;
use Kongono\Http\App;
use Kongono\Http\Request;
use Kongono\View\ViewEngine;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Zokeibi\Studio\OklchContrast;
use Zokeibi\Studio\ThemeTokenConfig;
use Zokeibi\Tests\Fixtures\PlanTier;
use Zokeibi\Tests\Fixtures\SampleOrderDto;
use Zokeibi\Zokeibi;

/**
 * Regression test suite verifying every security, CSP, WCAG, correctness, and runtime fix
 * from the Zokeibi deep audit.
 */
final class SecurityAndQualityRegressionTest extends TestCase
{
    private string $packageDir;

    protected function setUp(): void
    {
        $this->packageDir = dirname(__DIR__);
    }

    public function testStudioStoreAndRegistryBlockPathTraversal(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-traversal-');

        try {
            // Tampered zokeibi.json attempting to write CSS outside the project root
            file_put_contents(
                $tmpDir . DIRECTORY_SEPARATOR . 'zokeibi.json',
                json_encode([
                    'zokeibi' => '1.0.0',
                    'views_dir' => 'views',
                    'css_out' => '../../escaped.css',
                ], JSON_THROW_ON_ERROR),
            );

            $caught = false;
            try {
                $zokeibi->studio->saveAndBuild($tmpDir, ThemeTokenConfig::defaults(), $zokeibi->compiler);
            } catch (InvalidArgumentException) {
                $caught = true;
            }
            self::assertTrue($caught, 'Expected path traversal in css_out to be rejected.');

            $caughtAbs = false;
            try {
                $zokeibi->studio->resolveSafeProjectPath($tmpDir, '/etc/passwd');
            } catch (InvalidArgumentException) {
                $caughtAbs = true;
            }
            self::assertTrue($caughtAbs, 'Expected absolute path in config to be rejected.');

            $caughtRegistry = false;
            try {
                $zokeibi->registry->cssPath('../../composer.json');
            } catch (InvalidArgumentException) {
                $caughtRegistry = true;
            }
            self::assertTrue($caughtRegistry, 'Expected path traversal in ComponentRegistry::cssPath() to be rejected.');
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testThemeTokenConfigBlocksMalformedOklchAndDirectConstructorCssInjection(): void
    {
        self::assertFalse(ThemeTokenConfig::isValidColorValue('oklch(... ... ...)'));
        self::assertFalse(ThemeTokenConfig::isValidColorValue('oklch(1.2.3 0.1 10)'));
        self::assertFalse(ThemeTokenConfig::isValidColorValue("oklch(0.6 0.1 200)\n} body { display:none; }"));
        self::assertTrue(ThemeTokenConfig::isValidColorValue('oklch(0.65 0.145 355 / 0.8)'));
        self::assertTrue(ThemeTokenConfig::isValidColorValue('#06b6d4'));

        // Direct constructor bypass attempt must be filtered cleanly without throwing or injecting CSS
        $evil = new ThemeTokenConfig(
            fontScale: 'nonexistent',
            fontStack: 'nonexistent',
            customColors: [
                'safe-brand' => '#14b8a6',
                'bad; } body { display:none; /*' => 'red; }',
                'also-bad' => 'url(https://evil.example/leak)',
            ],
            customThemes: [
                'evil"] { body { display: none; } } [x="' => [
                    'hue' => 200.0,
                    'chroma' => 0.15,
                    'accent_lightness' => 0.60,
                    'mode' => 'dark',
                    'radius_px' => 12,
                ],
                'studio' => [
                    'hue' => 100.0,
                    'chroma' => 0.15,
                    'accent_lightness' => 0.60,
                    'mode' => 'dark',
                    'radius_px' => 12,
                ],
                'valid-ocean' => [
                    'hue' => 210.0,
                    'chroma' => 0.15,
                    'accent_lightness' => 0.56,
                    'mode' => 'dark',
                    'radius_px' => 12,
                ],
            ],
        );

        $css = $evil->toCssLayer();
        self::assertStringContainsString('--z-color-safe-brand: #14b8a6;', $css);
        self::assertStringContainsString('[data-zokeibi-theme="valid-ocean"]', $css);
        self::assertStringContainsString('--z-ink: oklch(0.955 0.006 264);', $css);
        self::assertStringContainsString('color-scheme: dark;', $css);
        self::assertStringNotContainsString('display:none', $css);
        self::assertStringNotContainsString('display: none', $css);
        self::assertStringNotContainsString('evil.example', $css);
        self::assertSame(1, substr_count($css, '[data-zokeibi-theme="studio"]'));

        $exporter = (new Zokeibi())->exporter;
        $tailwind = $exporter->export($evil, 'tailwind');
        self::assertStringContainsString('--color-safe-brand: #14b8a6;', $tailwind);
        self::assertStringNotContainsString('display:none', $tailwind);
        self::assertStringNotContainsString('evil.example', $tailwind);
    }

    public function testStudioControllerPreservesSafelistOnSaveAndLiveSync(): void
    {
        $tmpDir = $this->makeTempDir('zokeibi-safelist-');
        $prevEnv = getenv('ZOKEIBI_STUDIO_PROJECT');

        try {
            $zokeibi = new Zokeibi();
            $zokeibi->installer->init($tmpDir, 'sumi', 'default');
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'index.kgn.html', '<x-ui.button>Ok</x-ui.button>');
            $zokeibi->studio->save($tmpDir, new ThemeTokenConfig(safelist: ['modal', 'toast']));
            putenv('ZOKEIBI_STUDIO_PROJECT=' . $tmpDir);

            /** @var App $app */
            $app = require $this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

            $get = $app->handle(Request::create('GET', '/studio'));
            $html = (string) $get->getBody();
            preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m);
            $csrf = $m[1] ?? '';
            $cookies = [];
            foreach ($get->getHeader('Set-Cookie') as $hdr) {
                if (1 === preg_match('/^zokeibi_studio_session=([^;]+)/', $hdr, $cm)) {
                    $cookies['zokeibi_studio_session'] = $cm[1];
                }
            }

            $res = $app->handle(Request::create(
                'POST',
                '/studio/live-sync',
                parsedBody: [
                    '_csrf' => $csrf,
                    'hue' => '200',
                    'chroma' => '0.15',
                    'accent_lightness' => '0.58',
                    'mode' => 'dark',
                    'radius_px' => '12',
                ],
            )->withCookieParams($cookies));
            self::assertSame(200, $res->getStatusCode());

            $reloaded = $zokeibi->studio->load($tmpDir);
            self::assertSame(['modal', 'toast'], $reloaded->safelist);
        } finally {
            if (false === $prevEnv) {
                putenv('ZOKEIBI_STUDIO_PROJECT');
            } else {
                putenv('ZOKEIBI_STUDIO_PROJECT=' . $prevEnv);
            }
            $this->removeDir($tmpDir);
        }
    }

    public function testTemplatesBlockUnsafeUrlSchemesAndPreserveHttpsUrls(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-xss-urls-');

        try {
            $zokeibi->installer->add($tmpDir, ['button', 'banner', 'breadcrumb', 'command-palette', 'dropdown', 'sidebar', 'pagination'], true);
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            $tpl = <<<'HTML'
<section>
  <x-ui.button :href="$jsUrl">Bad Link</x-ui.button>
  <x-ui.button :href="$protoRel">Proto Rel</x-ui.button>
  <x-ui.button :kgn-post="$jsUrl" kgn-target="#out">Bad Post</x-ui.button>
  <x-ui.banner title="Notice" action-label="Click" :action-href="$vbUrl">Body</x-ui.banner>
  <x-ui.breadcrumb :items="$crumbs" />
  <x-ui.command-palette id="cp-url" items="docs:Docs:https://kongono.com:D|evil:Evil:javascript:alert(1):X" />
  <x-ui.dropdown id="dd-url" trigger="Links" items="Docs:https://kongono.com:D|Evil:javascript:alert(1):X" />
  <x-ui.sidebar items="#Links|Docs:https://kongono.com:New|Evil:data:text/html,<script>alert(1)</script>" />
  <x-ui.pagination :current="10" :total="3" :base-url="$jsUrl" />
</section>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'urls.kgn.html', $tpl);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('urls', [
                'jsUrl' => "\tJaVaScRiPt:alert(1)",
                'vbUrl' => 'vbscript:msgbox(1)',
                'protoRel' => '//evil.example/phish',
                'crumbs' => [
                    ['label' => 'Evil', 'href' => 'javascript:alert(1)'],
                    ['label' => 'Docs', 'href' => 'https://kongono.com/docs'],
                    ['label' => 'Current'],
                ],
            ]);

            self::assertStringNotContainsString('javascript:', strtolower($html));
            self::assertStringNotContainsString('vbscript:', strtolower($html));
            self::assertStringNotContainsString('data:text/html', strtolower($html));
            self::assertStringNotContainsString('//evil.example', $html);
            // Valid https:// URLs must survive pipe/colon parsing intact
            self::assertSame(4, substr_count($html, 'href="https://kongono.com'));
            // Pagination must clamp current=10 to total=3 and disable Next as a non-clickable <span>
            self::assertStringContainsString('Page 3 of 3', $html);
            self::assertStringContainsString('<span class="z-pagination-link" aria-disabled="true" aria-label="Next page">Next</span>', $html);
            self::assertStringNotContainsString('href="?page=8"', $html);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testComponentFunctionalAndEdgeCaseFixes(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-edge-');

        try {
            $zokeibi->installer->add(
                $tmpDir,
                ['segmented', 'multi-select', 'tabs', 'avatar', 'date-range', 'tree', 'chart', 'combobox', 'datepicker', 'dropzone', 'modal', 'drawer', 'rating', 'dropdown', 'popover', 'otp-input'],
                true,
            );
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            $tpl = <<<'HTML'
<section>
  <x-ui.segmented name="days" options="1:One|2:Two" :value="1" />
  <x-ui.multi-select name="nums" options="1:One|2:Two" selected="1,1" />
  <x-ui.tabs :tabs="$badTabs" default="safe" />
  <x-ui.avatar initials="造形美" name="Zokeibi" />
  <x-ui.date-range name="dr" start="2026-02-31" end="2026-99-99" />
  <x-ui.tree items="app/bootstrap.php|app|app//routes.php" />
  <x-ui.chart type="sparkline" title="Neg" data="A:-10|B:-5" />
  <x-ui.combobox name="reg" options="us:US" error="Required region" />
  <x-ui.datepicker name="due" error="Invalid date" />
  <x-ui.dropzone name="doc" error="File too large" />
  <x-ui.modal id="m-test" title="Test">Hi</x-ui.modal>
  <x-ui.drawer id="d-test" title="Test">Hi</x-ui.drawer>
  <x-ui.rating name="stars" max="5" value="4" />
  <x-ui.dropdown id="dd-test" trigger="Menu" items="Home:/" />
  <x-ui.popover id="pop-test" trigger="Info">Body</x-ui.popover>
  <x-ui.otp-input name="otp" length="6" value="123456789" />
</section>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'edge.kgn.html', $tpl);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('edge', [
                'badTabs' => [
                    ['id' => 'safe', 'label' => 'Safe'],
                    ['id' => "bad');set(x,1)//", 'label' => 'Injected'],
                ],
            ]);

            // Numeric string keys selected cleanly in segmented and multi-select (no duplicate chips)
            self::assertMatchesRegularExpression('/name="days"\s+value="1"\s+checked/', $html);
            self::assertSame(1, substr_count($html, 'data-ms-chip="1"'));
            self::assertMatchesRegularExpression('/data-value="1"\s+data-label="One"\s+aria-selected="true"/', $html);

            // Tabs sanitize single quotes out of Live expressions and wire runtime hooks
            self::assertStringNotContainsString("set(x,1)", $html);
            self::assertStringContainsString('data-z-tabs="true"', $html);
            self::assertStringContainsString('data-z-tab="safe"', $html);

            // Multibyte CJK initials remain valid UTF-8
            self::assertStringContainsString('<span>造形</span>', $html);
            self::assertTrue(mb_check_encoding($html, 'UTF-8'));

            // Invalid dates rejected cleanly
            self::assertStringNotContainsString('2026-02-31', $html);
            self::assertStringNotContainsString('2026-99-99', $html);

            // Tree preserves subtree and skips empty double-slash folders
            self::assertStringContainsString('bootstrap.php', $html);
            self::assertStringContainsString('routes.php', $html);
            self::assertStringNotContainsString('<summary>/</summary>', $html);

            // Negative sparkline points stay within viewBox 0..96
            self::assertStringNotContainsString('cy="804', $html);
            self::assertStringNotContainsString('cy="444', $html);

            // Combobox/datepicker/dropzone use defined .z-field-err and ARIA wiring
            self::assertStringNotContainsString('z-field-error', $html);
            self::assertSame(3, substr_count($html, 'class="z-field-err"'));
            self::assertSame(3, substr_count($html, 'aria-invalid="true"'));

            // Modal & Drawer place id on backdrop + data-z-close on close button
            self::assertStringContainsString('<div class="z-modal-backdrop" id="m-test"', $html);
            self::assertStringContainsString('<div class="z-drawer-backdrop" id="d-test"', $html);
            self::assertStringContainsString('data-z-close="#m-test"', $html);

            // Rating radio inputs carry aria-label directly
            self::assertStringContainsString('aria-label="4 of 5 stars"', $html);
            self::assertStringNotContainsString('<label class="z-rating-star" aria-label=', $html);

            // Dropdown & Popover use valid <div> wrapper (never flow <div> inside <span>)
            self::assertStringContainsString('<div class="z-dropdown">', $html);
            self::assertStringContainsString('<div class="z-popover-wrap">', $html);

            // OTP truncates initial hidden value to length=6 and omits maxlength=1 on digit 0
            self::assertStringContainsString('name="otp" value="123456"', $html);
            self::assertStringNotContainsString('123456789', $html);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testBlocksAndDtoGeneratorCompileAndRenderWithoutSyntaxOrPrecedenceErrors(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-compile-');

        try {
            $zokeibi->blocks->install($tmpDir, ['saas-shell', 'auth-login', 'settings-billing'], $zokeibi->installer, true);
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            // Compile & render blocks with live data — must NOT render literal {{ ... }} or @old(...)
            $engine = new ViewEngine($viewsDir, $cacheDir);
            $authHtml = $engine->render('blocks/auth-login', ['old' => ['email' => 'ada@example.com'], 'csrf' => 'tok']);
            self::assertStringContainsString('value="ada@example.com"', $authHtml);
            self::assertStringNotContainsString('@old(', $authHtml);
            self::assertStringContainsString('kgn-post="/auth/passkeys/login/options"', $authHtml);

            $billingHtml = $engine->render('blocks/settings-billing', ['planName' => 'Enterprise', 'workspaceName' => 'Acme', 'csrf' => 'tok']);
            self::assertStringContainsString('Enterprise', $billingHtml);
            self::assertStringNotContainsString('{{ $planName', $billingHtml);
            self::assertStringContainsString('deleteOpen', $billingHtml);

            // Compile & render DTO-generated table with real BackedEnum + array fields (operator precedence regression)
            $tableMarkup = $zokeibi->generator->generateTable(SampleOrderDto::class, '123orders', 'Orders', '/orders?page=');
            self::assertStringContainsString('@forelse ($orders as $row)', $tableMarkup);
            $zokeibi->installer->add($tmpDir, ['table', 'badge', 'empty-state', 'pagination'], true);
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'orders-table.kgn.html', $tableMarkup);

            $order = new SampleOrderDto(
                customerEmail: 'buyer@acme.test',
                password: 'super-secret-password',
                plan: PlanTier::Enterprise,
                region: 'us-east',
                scopes: ['read', 'deploy'],
                launchDate: '2026-12-01',
                autoRenew: true,
            );
            $tableHtml = $engine->render('orders-table', ['orders' => [$order], 'page' => 2, 'totalPages' => 4]);
            self::assertStringContainsString('buyer@acme.test', $tableHtml);
            self::assertStringContainsString('enterprise', $tableHtml);
            self::assertStringContainsString('read, deploy', $tableHtml);
            self::assertStringNotContainsString('super-secret-password', $tableHtml);
            self::assertStringContainsString('Page 2 of 4', $tableHtml);

            // Generator blocks javascript: action / baseUrl and invalid HTTP methods
            $blockedAction = false;
            try {
                $zokeibi->generator->generateForm(SampleOrderDto::class, 'javascript:alert(1)');
            } catch (InvalidArgumentException) {
                $blockedAction = true;
            }
            self::assertTrue($blockedAction);

            $blockedMethod = false;
            try {
                $zokeibi->generator->generateForm(SampleOrderDto::class, '/orders', 'TRACE');
            } catch (InvalidArgumentException) {
                $blockedMethod = true;
            }
            self::assertTrue($blockedMethod);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testCssTreeShakerMultiHyphenClassesAuditorRulesAndWatcher(): void
    {
        $zokeibi = new Zokeibi();

        // Multi-hyphen class prefixes must be detected accurately
        $detected = $zokeibi->compiler->detectComponentsInSource(
            '<div class="z-input-group-row"><div class="z-avatar-group"><label class="z-seg-item"></label></div></div>',
        );
        self::assertContains('input-group', $detected);
        self::assertContains('avatar-group', $detected);
        self::assertContains('segmented', $detected);

        // UiAuditor catches data-src bypass, ontoggle, javascript: URL, single-quoted bad enum
        $report = $zokeibi->auditor->auditSource(
            '<script data-src="/fake.js">alert(1)</script><details ontoggle="evil()"></details><a href="javascript:alert(1)">x</a><x-ui.button variant=\'neon\'>Hi</x-ui.button>',
            'evil.kgn.html',
        );
        $rules = array_map(static fn ($f): string => $f->rule, $report->findings);
        self::assertContains('csp-inline-script', $rules);
        self::assertContains('csp-inline-handler', $rules);
        self::assertContains('unsafe-url-scheme', $rules);
        self::assertContains('invalid-prop-enum', $rules);

        // UiProver rejects non-positive gzip budgets
        $caughtBudget = false;
        try {
            $zokeibi->prover->prove($this->packageDir . DIRECTORY_SEPARATOR . 'playground', null, 0.0);
        } catch (InvalidArgumentException) {
            $caughtBudget = true;
        }
        self::assertTrue($caughtBudget);

        // CssWatcher ignores vendor/node_modules and detects edits without external clearstatcache()
        $tmpDir = $this->makeTempDir('zokeibi-watch-reg-');
        try {
            mkdir($tmpDir . DIRECTORY_SEPARATOR . 'src', 0o775, true);
            mkdir($tmpDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'pkg', 0o775, true);
            $tsx = $tmpDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.tsx';
            file_put_contents($tsx, '<div className="z-btn">A</div>');
            $h1 = $zokeibi->watcher->fingerprint($tmpDir, $tmpDir);

            // Editing vendor/ must NOT change fingerprint
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'pkg' . DIRECTORY_SEPARATOR . 'Ignored.php', '<?php echo 1;');
            $hVendor = $zokeibi->watcher->fingerprint($tmpDir, $tmpDir);
            self::assertSame($h1, $hVendor);

            // Editing .tsx MUST change fingerprint even without caller clearstatcache()
            file_put_contents($tsx, '<div className="z-btn z-input-group">Updated longer content</div>');
            $h2 = $zokeibi->watcher->fingerprint($tmpDir, $tmpDir);
            self::assertNotSame($h1, $h2);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testAllFiveBuiltInThemesPassWcagAaContrast(): void
    {
        $pairs = [
            'sumi_accent' => [[0.985, 0.008, 355.0], [0.54, 0.165, 355.0]],
            'washi_accent' => [[0.985, 0.008, 355.0], [0.50, 0.16, 355.0]],
            'washi_amber' => [[0.49, 0.145, 75.0], [0.998, 0.002, 95.0]],
            'washi_emerald' => [[0.985, 0.008, 162.0], [0.46, 0.14, 162.0]],
            'indigo_accent' => [[0.985, 0.008, 268.0], [0.54, 0.185, 268.0]],
            'matcha_accent' => [[0.14, 0.02, 158.0], [0.74, 0.145, 158.0]],
            'kintsugi_accent' => [[0.14, 0.02, 74.0], [0.76, 0.145, 74.0]],
            'dark_ink_soft' => [[0.68, 0.012, 264.0], [0.228, 0.015, 264.0]],
            'washi_ink_soft' => [[0.48, 0.012, 264.0], [0.948, 0.006, 264.0]],
        ];

        foreach ($pairs as $name => [$fg, $bg]) {
            $ratio = OklchContrast::contrastRatio($fg[0], $fg[1], $fg[2], $bg[0], $bg[1], $bg[2]);
            self::assertGreaterThanOrEqual(4.5, $ratio, sprintf('Theme pair "%s" failed WCAG AA: %.2f:1', $name, $ratio));
        }
    }

    private function makeTempDir(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(5));
        mkdir($path, 0o775, true);

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
