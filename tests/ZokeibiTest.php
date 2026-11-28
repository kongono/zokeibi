<?php

declare(strict_types=1);

namespace Zokeibi\Tests;

use InvalidArgumentException;
use Zokeibi\Zokeibi;
use Zokeibi\Studio\OklchContrast;
use Zokeibi\Studio\ThemeTokenConfig;
use Zokeibi\Tests\Fixtures\SampleOrderDto;
use Kongono\Http\App;
use Kongono\Http\Request;
use Kongono\View\ViewEngine;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ZokeibiTest extends TestCase
{
    private string $packageDir;

    protected function setUp(): void
    {
        $this->packageDir = dirname(__DIR__);
    }

    public function testRegistryLoadsAll63ComponentsAndAssetsExist(): void
    {
        $zokeibi = new Zokeibi();

        self::assertSame(63, $zokeibi->registry->count());
        self::assertSame(['sumi', 'washi', 'indigo', 'matcha', 'kintsugi'], $zokeibi->registry->themes());
        self::assertSame(['sharp', 'default', 'rounded'], $zokeibi->registry->radiusPresets());

        foreach ($zokeibi->registry->all() as $id => $schema) {
            self::assertSame($id, $schema->id);
            self::assertSame('x-ui.' . $id, $schema->tag);
            self::assertFileExists($zokeibi->registry->cssPath('components/' . $schema->css));
            self::assertFileExists($zokeibi->registry->templatePath($schema->template));
            self::assertNotSame('', $schema->example);
        }

        $decoded = json_decode($zokeibi->registry->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('zokeibi', $decoded['name']);
        self::assertSame('造形美', $decoded['kanji']);
        self::assertSame(63, $decoded['component_count']);
    }

    public function testCssTreeShakerReducesBundleAndResolvesDependencies(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-shake-');

        try {
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            mkdir($viewsDir, 0775, true);
            file_put_contents(
                $viewsDir . DIRECTORY_SEPARATOR . 'landing.kgn.html',
                '<main><x-ui.popover id="p1" trigger="Menu">Hi</x-ui.popover><x-ui.badge variant="emerald">OK</x-ui.badge></main>',
            );

            $outCss = $tmpDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'zokeibi.min.css';
            $report = $zokeibi->compiler->buildFromDirectory($viewsDir, $outCss);

            // popover declares css_deps: ["button"], so button MUST be pulled in automatically
            self::assertSame(['button', 'badge', 'popover'], $report->usedComponents);
            self::assertCount(60, $report->skippedComponents);
            self::assertSame(1, $report->scannedFiles);
            self::assertFileExists($outCss);
            self::assertGreaterThan(35.0, $report->treeShakeSavingsPercent);
            self::assertLessThan($report->fullBundleMinBytes, $report->minifiedBytes);
            self::assertStringContainsString('@layer zokeibi.tokens', $report->css);
            self::assertStringContainsString('oklch(', $report->css);
            self::assertStringContainsString(':popover-open', $report->css);
            // Unused table & accordion CSS must be absent
            self::assertStringNotContainsString('.z-table-wrap', $report->css);
            self::assertStringNotContainsString('.z-accordion', $report->css);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testUiAuditorScores100OnPlaygroundAndCatchesAllViolations(): void
    {
        $zokeibi = new Zokeibi();

        $playgroundReport = $zokeibi->auditor->auditDirectory($this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'views');
        self::assertSame(100, $playgroundReport->score);
        self::assertTrue($playgroundReport->passed);
        self::assertSame(0, $playgroundReport->errorCount);
        self::assertSame(0, $playgroundReport->warningCount);

        $badTemplate = <<<'HTML'
<div>
  <x-ui.hallucinated title="nope" />
  <x-ui.button variant="neon">Bad Enum</x-ui.button>
  <x-ui.button variant="primary" k-click="set(open, true)">Valid Live</x-ui.button>
  <script>alert('xss')</script>
  <script src="/_kongono/live.js" defer></script>
  <button onclick="doEvil()">Evil</button>
  <div style="color:red">Inline Style</div>
  <button></button>
</div>
HTML;

        $badReport = $zokeibi->auditor->auditSource($badTemplate, 'bad.kgn.html');
        self::assertFalse($badReport->passed);
        self::assertLessThan(40, $badReport->score);

        $rules = array_map(static fn ($f): string => $f->rule, $badReport->findings);
        self::assertContains('unknown-component', $rules);
        self::assertContains('invalid-prop-enum', $rules);
        self::assertContains('csp-inline-script', $rules);
        self::assertContains('csp-inline-handler', $rules);
        self::assertContains('csp-inline-style', $rules);
        self::assertContains('a11y-empty-button', $rules);
        // External script with src= must NOT be flagged (only 1 csp-inline-script finding)
        self::assertSame(1, count(array_filter($rules, static fn (string $r): bool => 'csp-inline-script' === $r)));

        // Typo & color alias "Did you mean?" suggestions with 5W1H fields
        $typoReport = $zokeibi->auditor->auditSource('<x-ui.buton variant="primary">Hi</x-ui.buton><x-ui.badge variant="green">OK</x-ui.badge>', 'typo.kgn.html');
        self::assertFalse($typoReport->passed);
        self::assertCount(2, $typoReport->findings);
        self::assertContains('<x-ui.button>', $typoReport->findings[0]->didYouMean);
        self::assertContains('emerald', $typoReport->findings[1]->didYouMean);
        self::assertNotSame('', $typoReport->findings[0]->why);
        self::assertSame('typo.kgn.html:1', $typoReport->findings[0]->toArray()['where']);
    }

    public function testComponentInstallerInitAddAndEject(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-install-');

        try {
            $init = $zokeibi->installer->init($tmpDir, 'indigo', 'rounded');
            self::assertSame(['zokeibi.json'], $init->written);
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'zokeibi.json');

            $add = $zokeibi->installer->add($tmpDir, ['button', 'card', 'popover']);
            self::assertCount(3, $add->written);
            self::assertSame([], $add->skipped);
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'popover.kgn.html');

            // Re-running without force skips existing user-owned templates
            $addAgain = $zokeibi->installer->add($tmpDir, ['button', 'card']);
            self::assertSame([], $addAgain->written);
            self::assertCount(2, $addAgain->skipped);

            // Eject CSS layers + selected components
            $eject = $zokeibi->installer->ejectCss($tmpDir, ['button']);
            self::assertGreaterThanOrEqual(6, count($eject->written));
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'zokeibi-css' . DIRECTORY_SEPARATOR . 'tokens.css');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'zokeibi-css' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'button.css');
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testAll63ComponentsCompileAndRenderCleanlyWithXssEscaping(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-render-');

        try {
            $zokeibi->installer->add($tmpDir, $zokeibi->registry->ids(), true);
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0775, true);

            $page = <<<'HTML'
<section>
  <x-ui.button variant="primary" k-click="set(a, 1)">Click</x-ui.button>
  <x-ui.badge variant="emerald" dot="true">Ready</x-ui.badge>
  <x-ui.card :title="$xssTitle" badge="Safe">Card Body</x-ui.card>
  <x-ui.alert variant="info" title="Notice">Safe alert</x-ui.alert>
  <x-ui.kbd>⌘K</x-ui.kbd>
  <x-ui.avatar initials="KT" name="Zokeibi" status="online" />
  <x-ui.input name="email" type="email" label="Email" error="Required" />
  <x-ui.select name="plan" label="Plan"><option value="pro">Pro</option></x-ui.select>
  <x-ui.textarea name="bio" label="Bio" rows="2" />
  <x-ui.switch name="csp" label="Strict CSP" checked="true" />
  <x-ui.progress label="Build" value="80" max="100" variant="emerald" />
  <x-ui.skeleton variant="line" lines="2" />
  <x-ui.empty-state icon="形" title="Empty" description="Nothing yet">Action</x-ui.empty-state>
  <x-ui.kpi label="Gzip" value="4.8 KB" delta="-70%" trend="up" variant="emerald" />
  <x-ui.table caption="Demo" searchable="true" sortable="true"><thead><tr><th data-z-sort="string">Col</th></tr></thead><tbody><tr><td>Val</td></tr></tbody></x-ui.table>
  <x-ui.tabs tabs="a:Tab A|b:Tab B" default="a"><div k-show="tab === 'a'">Panel A</div></x-ui.tabs>
  <x-ui.accordion title="FAQ" name="g1" open="true">Answer</x-ui.accordion>
  <x-ui.popover id="pop1" trigger="Open" title="Pop">Top layer</x-ui.popover>
  <x-ui.tooltip text="Tip" position="top"><span>Hover</span></x-ui.tooltip>
  <x-ui.modal id="m1" title="Confirm" show="modalOpen" variant="danger">Body</x-ui.modal>
  <x-ui.drawer id="d1" title="Inspect" show="drawerOpen">Details</x-ui.drawer>
  <x-ui.toast title="Done" description="Saved" variant="emerald" />
  <x-ui.command-palette id="cmd1" trigger-label="Jump" items="home:Home:/:H|studio:Studio:/studio:S" />
  <x-ui.combobox name="region" label="Region" options="us-east:US East|eu-central:EU Central" value="us-east" />
  <x-ui.dropzone name="doc" label="Upload File" accept="application/pdf" />
  <x-ui.datepicker name="due" label="Due Date" value="2026-12-01" presets="true" />
  <x-ui.stepper steps="One|Two|Three" current="2" />
  <x-ui.pagination current="2" total="5" base-url="/items?page=" />
  <x-ui.date-range name="win" label="Window" start="2026-11-01" end="2026-11-14" presets="true" />
  <x-ui.multi-select name="tags" label="Tags" options="a:Alpha|b:Beta" selected="a" />
  <x-ui.chart type="bar" title="Bars" data="Mon:10|Tue:20" />
  <x-ui.chart type="sparkline" title="Spark" data="10|20|15" />
  <x-ui.chart type="donut" title="Donut" data="A:60|B:40" />
  <x-ui.editor name="md" label="Notes" value="**Hi**" />
  <x-ui.otp-input name="otp" label="Code" length="6" />
  <x-ui.copy-field label="Secret" value="kgn_pat_123456" masked="true" />
  <x-ui.timeline items="Shipped|Now|All green|emerald" />
  <x-ui.breadcrumb items="Home:/|Settings" />
  <x-ui.checkbox name="agree" label="Agree" checked="true" />
  <x-ui.radio-group name="tier" label="Tier" layout="cards" value="pro" options="free:Free:0|pro:Pro:29" />
  <x-ui.dropdown id="dd1" trigger="Menu" items="Edit:/edit:E|---|danger:Delete:/del" />
  <x-ui.segmented name="seg" label="Mode" value="a" options="a:A|b:B" />
  <x-ui.spinner size="sm" label="Loading" />
  <x-ui.slider name="seats" label="Seats" min="1" max="50" value="10" unit=" seats" />
  <x-ui.divider label="Or" />
  <x-ui.description-list columns="2" items="Key:Value|Env:Prod" />
  <x-ui.code-block filename="app.php" language="php">echo 'hi';</x-ui.code-block>
  <x-ui.tag-input name="topics" label="Topics" value="a,b" />
  <x-ui.banner variant="emerald" title="Ready" dismissible="true">Live</x-ui.banner>
  <x-ui.rating name="stars" label="Rating" value="5" />
  <x-ui.sidebar active="/home" items="#Main|Home:/home:1" />
  <x-ui.input-group name="sub" label="URL" prefix="https://" suffix=".dev" value="app" />
  <x-ui.number-input name="qty" label="Qty" min="1" max="10" value="2" />
  <x-ui.avatar-group max="2" items="OS:Oshie|CH:Chie|HT:Hantei" />
  <x-ui.carousel label="Slides" items="One|Body;;Two|More" />
  <x-ui.tree label="Files" items="app/bootstrap.php|src/App.php" />
  <x-ui.password-input name="pw" label="Password" strength="true" />
  <x-ui.signature-pad name="lease_sig" label="Tenant Signature" mode="both" required="true" />
  <x-ui.money-input name="rent_cents" label="Monthly Rent" value="185000" currency="USD" symbol="$" />
  <x-ui.alert-dialog id="ad1" title="Terminate Lease" description="Cancels drafts" confirm-text="DELETE" confirm-label="Delete" />
  <x-ui.ai-review title="Receipt OCR" model="Gemini" source-excerpt="TOTAL $184.50" fields="vendor|Vendor|Home Depot|98%;;category|Category|Plumbing|62%" />
  <x-ui.approval-card title="Rent Increase" agent="Chie AI" risk="high" summary="Update ledger" changes="Rent|$1,800|$1,850" note-field="true" />
  <x-ui.filter-bar views="all:All|overdue:Overdue" active-view="overdue" filters="status:Status:Overdue" options="status:Status:Paid" />
  <x-ui.compare-slider before-label="Before" after-label="After" before-text="Old" after-text="New" initial="45" />
  <x-ui.countdown seconds="95" urgent-seconds="60" prefix="Closes in" />
</section>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'all63.kgn.html', $page);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('all63', ['xssTitle' => '<script>alert(1)</script>']);

            // Zero uncompiled <x-ui.*> tags
            self::assertStringNotContainsString('<x-ui.', $html);
            // XSS auto-escaped
            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            // Zero inline style or inline onclick across all 56 rendered primitives
            self::assertStringNotContainsString('style="', $html);
            self::assertStringNotContainsString('onclick=', $html);
            // Modern semantic HTML & ARIA attributes present
            self::assertStringContainsString('role="switch"', $html);
            self::assertStringContainsString('popover="auto"', $html);
            self::assertStringContainsString('popovertarget="pop1"', $html);
            self::assertStringContainsString('<progress', $html);
            self::assertStringContainsString('<details', $html);
            self::assertStringContainsString('role="tablist"', $html);
            self::assertStringContainsString('role="dialog"', $html);
            self::assertStringContainsString('role="tooltip"', $html);
            self::assertStringContainsString('role="combobox"', $html);
            self::assertStringContainsString('role="listbox"', $html);
            self::assertStringContainsString('aria-current="step"', $html);
            self::assertStringContainsString('aria-current="page"', $html);
            self::assertStringContainsString('data-z-cmdk', $html);
            self::assertStringContainsString('data-z-dropzone', $html);
            self::assertStringContainsString('data-z-datepicker', $html);
            self::assertStringContainsString('data-z-daterange', $html);
            self::assertStringContainsString('data-z-multiselect', $html);
            self::assertStringContainsString('aria-multiselectable="true"', $html);
            self::assertStringContainsString('<svg class="z-chart-svg"', $html);
            self::assertStringContainsString('<svg class="z-chart-donut"', $html);
            self::assertStringContainsString('data-z-editor', $html);
            self::assertStringContainsString('data-z-otp', $html);
            self::assertStringContainsString('data-z-copy', $html);
            self::assertStringContainsString('z-timeline', $html);
            self::assertStringContainsString('aria-label="Breadcrumb"', $html);
            self::assertStringContainsString('data-z-table-search', $html);
            self::assertStringContainsString('z-checkbox', $html);
            self::assertStringContainsString('z-radio-group', $html);
            self::assertStringContainsString('role="menu"', $html);
            self::assertStringContainsString('role="radiogroup"', $html);
            self::assertStringContainsString('z-spinner', $html);
            self::assertStringContainsString('data-z-slider', $html);
            self::assertStringContainsString('role="separator"', $html);
            self::assertStringContainsString('<dl class="z-dl', $html);
            self::assertStringContainsString('z-codeblock', $html);
            self::assertStringContainsString('data-z-taginput', $html);
            self::assertStringContainsString('z-banner', $html);
            self::assertStringContainsString('z-rating', $html);
            self::assertStringContainsString('z-sidebar', $html);
            self::assertStringContainsString('z-input-group', $html);
            self::assertStringContainsString('data-z-number', $html);
            self::assertStringContainsString('z-avatar-group', $html);
            self::assertStringContainsString('aria-roledescription="carousel"', $html);
            self::assertStringContainsString('role="tree"', $html);
            self::assertStringContainsString('data-z-password', $html);
            self::assertStringContainsString('data-z-signature', $html);
            self::assertStringContainsString('data-z-money="true"', $html);
            self::assertStringContainsString('value="1,850.00"', $html);
            self::assertStringContainsString('value="185000"', $html);
            self::assertStringContainsString('role="alertdialog"', $html);
            self::assertStringContainsString('data-z-static-backdrop="true"', $html);
            self::assertStringContainsString('z-ai-review', $html);
            self::assertStringContainsString('z-approval-high', $html);
            self::assertStringContainsString('data-z-filter-bar="true"', $html);
            self::assertStringContainsString('data-z-compare="true"', $html);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testPlaygroundHttpEndpointsServeStrictCspAndRegistry(): void
    {
        /** @var App $app */
        $app = require $this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

        $home = $app->handle(Request::create('GET', '/'));
        self::assertSame(200, $home->getStatusCode());
        $csp = $home->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("style-src 'self'", $csp);
        $body = (string) $home->getBody();
        self::assertStringContainsString('Zokeibi', $body);
        self::assertStringContainsString('data-zokeibi-theme="sumi"', $body);
        self::assertStringContainsString('/assets/zokeibi-runtime.js', $body);
        self::assertStringNotContainsString('<x-ui.', $body);

        $reg = $app->handle(Request::create('GET', '/api/registry.json'));
        self::assertSame(200, $reg->getStatusCode());
        $regJson = json_decode((string) $reg->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($regJson);
        self::assertSame(63, $regJson['component_count']);
        self::assertIsArray($regJson['blocks']);
        self::assertCount(8, $regJson['blocks']);

        $health = $app->handle(Request::create('GET', '/api/v1/health'));
        self::assertSame(200, $health->getStatusCode());
        $healthJson = json_decode((string) $health->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($healthJson);
        self::assertSame('ok', $healthJson['status']);

        $css = $app->handle(Request::create('GET', '/assets/zokeibi.min.css'));
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('@layer zokeibi.tokens', (string) $css->getBody());
    }

    public function testCliListSchemaAndAuditSubcommands(): void
    {
        $bin = $this->packageDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'zokeibi';

        $listOut = [];
        $listCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' list --json', $listOut, $listCode);
        self::assertSame(0, $listCode);
        $listDecoded = json_decode(implode("\n", $listOut), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($listDecoded);
        self::assertCount(63, $listDecoded);

        $schemaOut = [];
        $schemaCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' schema popover', $schemaOut, $schemaCode);
        self::assertSame(0, $schemaCode);
        $schemaDecoded = json_decode(implode("\n", $schemaOut), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schemaDecoded);
        self::assertSame('x-ui.popover', $schemaDecoded['tag']);

        $auditOut = [];
        $auditCode = 0;
        $viewsArg = '--views=' . $this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'views';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' audit ' . escapeshellarg($viewsArg) . ' --strict --json', $auditOut, $auditCode);
        self::assertSame(0, $auditCode);
        $auditDecoded = json_decode(implode("\n", $auditOut), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($auditDecoded);
        self::assertSame(100, $auditDecoded['score']);
    }

    public function testOklchContrastMathAndWcagAudit(): void
    {
        $hex = OklchContrast::oklchToHex(0.65, 0.145, 355.0);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);

        $whiteBlack = OklchContrast::contrastRatio(1.0, 0.0, 0.0, 0.0, 0.0, 0.0);
        self::assertSame(21.0, $whiteBlack);

        // Bright gold accent should recommend dark ink; deep indigo should recommend light ink
        self::assertLessThan(0.5, OklchContrast::recommendedAccentInkLightness(0.82, 0.15, 80.0));
        self::assertGreaterThan(0.8, OklchContrast::recommendedAccentInkLightness(0.45, 0.16, 268.0));

        $checks = OklchContrast::auditConfig(ThemeTokenConfig::defaults());
        self::assertCount(4, $checks);
        foreach ($checks as $check) {
            self::assertTrue($check->passesAa, sprintf('Expected default check %s to pass WCAG AA', $check->id));
        }
    }

    public function testStudioStoreCompilesCustomTokensAndBlocksCssInjection(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-studio-');

        try {
            $zokeibi->installer->init($tmpDir, 'sumi', 'default');
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'page.kgn.html', '<x-ui.button variant="primary">Save</x-ui.button>');

            $config = new ThemeTokenConfig(
                activeTheme: 'acme-ocean',
                radiusPreset: 'rounded',
                hue: 212.0,
                chroma: 0.16,
                accentLightness: 0.62,
                mode: 'dark',
                radiusPx: 16,
                fontScale: 'comfortable',
                fontStack: 'geometric',
                customColors: ['brand-teal' => 'oklch(0.72 0.14 185)', 'promo-gold' => '#f59e0b'],
                customThemes: [
                    'acme-ocean' => [
                        'hue' => 212.0,
                        'chroma' => 0.16,
                        'accent_lightness' => 0.62,
                        'mode' => 'dark',
                        'radius_px' => 16,
                    ],
                ],
            );

            $report = $zokeibi->studio->saveAndBuild($tmpDir, $config, $zokeibi->compiler);
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'zokeibi.json');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zokeibi.min.css');
            self::assertStringContainsString('--z-color-brand-teal:oklch(0.72 0.14 185)', $report->css);
            self::assertStringContainsString('--z-color-promo-gold:#f59e0b', $report->css);
            self::assertStringContainsString('[data-zokeibi-theme="acme-ocean"]', $report->css);

            $reloaded = $zokeibi->studio->load($tmpDir);
            self::assertSame(212.0, $reloaded->hue);
            self::assertSame('oklch(0.72 0.14 185)', $reloaded->customColors['brand-teal']);
            self::assertArrayHasKey('acme-ocean', $reloaded->customThemes);

            // CSS injection must be rejected
            $this->expectException(InvalidArgumentException::class);
            ThemeTokenConfig::assertValidColorValue('red; } body { display: none;');
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testStudioHttpEndpointsEnforceCsrfAndPersistTokens(): void
    {
        $tmpDir = $this->makeTempDir('zokeibi-studio-http-');
        $prevEnv = getenv('ZOKEIBI_STUDIO_PROJECT');

        try {
            $zokeibi = new Zokeibi();
            $zokeibi->installer->init($tmpDir, 'sumi', 'default');
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.kgn.html', '<x-ui.button variant="primary">Go</x-ui.button>');
            putenv('ZOKEIBI_STUDIO_PROJECT=' . $tmpDir);

            /** @var App $app */
            $app = require $this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

            $studioGet = $app->handle(Request::create('GET', '/studio'));
            self::assertSame(200, $studioGet->getStatusCode());
            $csp = $studioGet->getHeaderLine('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringContainsString("style-src 'self'", $csp);
            $html = (string) $studioGet->getBody();
            self::assertStringContainsString('/assets/studio.js', $html);
            self::assertStringNotContainsString('<x-ui.', $html);
            self::assertStringNotContainsString('style="', $html);

            // Extract CSRF token and session cookie
            $csrfMatch = [];
            self::assertSame(1, preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $csrfMatch));
            $csrf = $csrfMatch[1] ?? '';
            self::assertNotSame('', $csrf);

            $cookies = [];
            foreach ($studioGet->getHeader('Set-Cookie') as $cookieHeader) {
                if (1 === preg_match('/^zokeibi_studio_session=([^;]+)/', $cookieHeader, $cm)) {
                    $cookies['zokeibi_studio_session'] = $cm[1];
                }
            }
            self::assertArrayHasKey('zokeibi_studio_session', $cookies);

            // POST without CSRF token must be blocked (403)
            $blocked = $app->handle(Request::create('POST', '/studio/save', parsedBody: ['hue' => '210']));
            self::assertSame(403, $blocked->getStatusCode());

            // POST /studio/save with valid CSRF saves zokeibi.json & compiles CSS
            $saved = $app->handle(Request::create(
                'POST',
                '/studio/save',
                parsedBody: [
                    '_csrf' => $csrf,
                    'hue' => '195',
                    'chroma' => '0.155',
                    'accent_lightness' => '0.64',
                    'mode' => 'dark',
                    'radius_px' => '14',
                    'font_scale' => 'default',
                    'font_stack' => 'geometric',
                ],
            )->withCookieParams($cookies));
            self::assertSame(303, $saved->getStatusCode());

            // POST /studio/colors/add persists a custom color token
            $addedColor = $app->handle(Request::create(
                'POST',
                '/studio/colors/add',
                parsedBody: [
                    '_csrf' => $csrf,
                    'color_name' => 'brand-cyan',
                    'color_value' => '#06b6d4',
                ],
            )->withCookieParams($cookies));
            self::assertSame(303, $addedColor->getStatusCode());

            // GET /studio/tokens.css & /api/registry.json reflect saved Studio tokens
            $tokensCss = $app->handle(Request::create('GET', '/studio/tokens.css'));
            self::assertSame(200, $tokensCss->getStatusCode());
            self::assertStringContainsString('--z-hue: 195.0;', (string) $tokensCss->getBody());
            self::assertStringContainsString('--z-color-brand-cyan: #06b6d4;', (string) $tokensCss->getBody());

            $registryRes = $app->handle(Request::create('GET', '/api/registry.json'));
            $regData = json_decode((string) $registryRes->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($regData);
            self::assertIsArray($regData['studio']);
            self::assertSame(195, $regData['studio']['hue']);
            self::assertIsArray($regData['studio']['custom_colors']);
            self::assertSame('#06b6d4', $regData['studio']['custom_colors']['brand-cyan']);

            // Universal /studio/export endpoint supports w3c-tokens and tailwind
            $w3cRes = $app->handle(Request::create('GET', '/studio/export', query: ['format' => 'w3c-tokens']));
            self::assertSame(200, $w3cRes->getStatusCode());
            self::assertStringContainsString('application/json', $w3cRes->getHeaderLine('Content-Type'));
            $w3cData = json_decode((string) $w3cRes->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($w3cData);
            self::assertArrayHasKey('$schema', $w3cData);

            $twRes = $app->handle(Request::create('GET', '/studio/export', query: ['format' => 'tailwind']));
            self::assertSame(200, $twRes->getStatusCode());
            self::assertStringContainsString('@theme {', (string) $twRes->getBody());
            self::assertStringContainsString('--color-brand-cyan: #06b6d4;', (string) $twRes->getBody());

            // POST /studio/live-sync requires CSRF (403 without) and returns live JSON proof when authenticated
            $syncBlocked = $app->handle(Request::create('POST', '/studio/live-sync', parsedBody: ['hue' => '180']));
            self::assertSame(403, $syncBlocked->getStatusCode());

            $syncOk = $app->handle(Request::create(
                'POST',
                '/studio/live-sync',
                parsedBody: [
                    '_csrf' => $csrf,
                    'hue' => '168',
                    'chroma' => '0.145',
                    'accent_lightness' => '0.66',
                    'mode' => 'dark',
                    'radius_px' => '12',
                ],
            )->withCookieParams($cookies));
            self::assertSame(200, $syncOk->getStatusCode());
            $syncData = json_decode((string) $syncOk->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($syncData);
            self::assertTrue($syncData['ok']);
            self::assertIsArray($syncData['proof']);
            self::assertTrue($syncData['proof']['passed']);

            // GET /studio/prove returns live UiProver Readiness Certificate JSON
            $proveRes = $app->handle(Request::create('GET', '/studio/prove'));
            self::assertSame(200, $proveRes->getStatusCode());
            $proveData = json_decode((string) $proveRes->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($proveData);
            self::assertTrue($proveData['passed']);
            self::assertSame('A+', $proveData['grade']);
        } finally {
            if (false === $prevEnv) {
                putenv('ZOKEIBI_STUDIO_PROJECT');
            } else {
                putenv('ZOKEIBI_STUDIO_PROJECT=' . $prevEnv);
            }
            $this->removeDir($tmpDir);
        }
    }

    public function testFrameworkAgnosticTreeShakingSafelistAndIgnoredDirs(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-anyfw-');

        try {
            mkdir($tmpDir . DIRECTORY_SEPARATOR . 'src', 0o775, true);
            mkdir($tmpDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'pkg', 0o775, true);

            // React TSX, Vue SFC, and Twig templates using plain .z-* classes (no <x-ui.*>)
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.tsx', '<div className="z-modal"><button className="z-btn z-btn-primary">Go</button></div>');
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Metric.vue', '<template><div class="z-kpi">42</div></template>');
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'list.twig', '<div class="z-table-wrap"><table class="z-table"></table></div>');
            // Must be ignored (inside node_modules/)
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'pkg' . DIRECTORY_SEPARATOR . 'Fake.jsx', '<details className="z-accordion"></details>');

            // Safelist toast inside zokeibi.json
            $zokeibi->studio->save($tmpDir, new ThemeTokenConfig(safelist: ['toast']));

            $report = $zokeibi->compiler->buildFromDirectory($tmpDir);
            self::assertSame(['button', 'kpi', 'table', 'modal', 'toast'], $report->usedComponents);
            self::assertNotContains('accordion', $report->usedComponents);
            self::assertSame(3, $report->scannedFiles);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testVanillaRuntimeInstallTokenExporterAndZeroRuntimeComposerDeps(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-free-');

        try {
            // 1. Composer zero runtime dependency check
            $composerRaw = file_get_contents($this->packageDir . DIRECTORY_SEPARATOR . 'composer.json');
            self::assertIsString($composerRaw);
            $composer = json_decode($composerRaw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($composer);
            self::assertIsArray($composer['require']);
            self::assertSame(['php'], array_keys($composer['require']));

            // 2. Install strict-CSP vanilla zokeibi-runtime.js
            $runtimeReport = $zokeibi->installer->installRuntime($tmpDir);
            self::assertCount(1, $runtimeReport->written);
            $runtimePath = $tmpDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zokeibi-runtime.js';
            self::assertFileExists($runtimePath);
            $js = (string) file_get_contents($runtimePath);
            self::assertStringContainsString('data-z-open', $js);
            self::assertStringContainsString('data-z-tab', $js);
            self::assertStringNotContainsString('eval(', $js);

            // 3. Export W3C DTCG JSON and Tailwind v4 @theme via CLI
            $zokeibi->studio->save($tmpDir, new ThemeTokenConfig(
                hue: 185.0,
                customColors: ['brand-sea' => '#0891b2'],
            ));
            $bin = $this->packageDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'zokeibi';
            $twOut = $tmpDir . DIRECTORY_SEPARATOR . 'theme.css';
            $lines = [];
            $code = 0;
            exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin)
                . ' export --format=tailwind --dir=' . escapeshellarg($tmpDir)
                . ' --out=' . escapeshellarg($twOut),
                $lines,
                $code,
            );
            self::assertSame(0, $code);
            self::assertFileExists($twOut);
            $twCss = (string) file_get_contents($twOut);
            self::assertStringContainsString('@theme {', $twCss);
            self::assertStringContainsString('--color-brand-sea: #0891b2;', $twCss);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testBlockCatalogInstallsAllEightSaasBlocksWithPrimitivesAndPassesAudit(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-blocks-');

        try {
            $allBlocks = $zokeibi->blocks->list();
            self::assertCount(8, $allBlocks);

            $report = $zokeibi->blocks->install($tmpDir, $zokeibi->blocks->ids(), $zokeibi->installer);
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'saas-shell.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'auth-login.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'settings-billing.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'pricing-grid.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'empty-onboarding.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'error-page.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'analytics-dashboard.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks' . DIRECTORY_SEPARATOR . 'api-keys-audit.kgn.html');
            // Required UI primitives auto-installed alongside blocks
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'command-palette.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'chart.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'date-range.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'copy-field.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'otp-input.kgn.html');
            self::assertFileExists($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'timeline.kgn.html');
            self::assertGreaterThan(20, count($report->written));

            $audit = $zokeibi->auditor->auditDirectory($tmpDir . DIRECTORY_SEPARATOR . 'views');
            self::assertTrue($audit->passed, 'Blocks audit failed: ' . json_encode($audit->toArray()));
            self::assertSame(100, $audit->score);
            self::assertSame(0, $audit->errorCount);
            self::assertSame(0, $audit->warningCount);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testDtoUiGeneratorBuildsAccessibleFormAndTableThatPassStrictAudit(): void
    {
        $zokeibi = new Zokeibi();

        $form = $zokeibi->generator->generateForm(SampleOrderDto::class, '/orders', 'POST', '#orders-out', 'Create Order');
        self::assertStringContainsString('<input type="hidden" name="_csrf"', $form);
        self::assertStringContainsString('kgn-post="/orders" kgn-target="#orders-out"', $form);
        self::assertStringContainsString('<x-ui.input name="customerEmail" type="email"', $form);
        self::assertStringContainsString('<x-ui.input name="password" type="password"', $form);
        self::assertStringNotContainsString("@old('password')", $form);
        self::assertStringContainsString('<x-ui.select name="plan"', $form);
        self::assertStringContainsString('<option value="enterprise">Enterprise</option>', $form);
        self::assertStringContainsString('<x-ui.multi-select name="scopes"', $form);
        self::assertStringContainsString('<x-ui.datepicker name="launchDate"', $form);
        self::assertStringContainsString('<x-ui.switch name="autoRenew"', $form);
        self::assertStringContainsString('<x-ui.editor name="body"', $form);
        self::assertStringContainsString('<x-ui.textarea name="notes"', $form);

        $formAudit = $zokeibi->auditor->auditSource($form, 'order-form.kgn.html');
        self::assertTrue($formAudit->passed);
        self::assertSame(100, $formAudit->score);
        self::assertSame(0, $formAudit->warningCount);

        $table = $zokeibi->generator->generateTable(SampleOrderDto::class, 'orders', 'Recent Orders', '/orders?page=', '#orders-table');
        self::assertStringContainsString('<x-ui.table caption="Recent Orders" searchable="true" sortable="true">', $table);
        self::assertStringContainsString('data-z-sort="string"', $table);
        self::assertStringNotContainsString('Password', $table);
        self::assertStringContainsString('<x-ui.empty-state', $table);
        self::assertStringContainsString('<x-ui.pagination', $table);

        $tableAudit = $zokeibi->auditor->auditSource($table, 'order-table.kgn.html');
        self::assertTrue($tableAudit->passed);
        self::assertSame(100, $tableAudit->score);
        self::assertSame(0, $tableAudit->warningCount);

        // CLI `zokeibi form --file=...` works out-of-the-box without Composer autoload config
        $bin = $this->packageDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'zokeibi';
        $fixture = $this->packageDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'SampleOrderDto.php';
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' form ' . escapeshellarg($fixture) . ' --action=/orders', $out, $code);
        self::assertSame(0, $code);
        self::assertStringContainsString('customerEmail', implode("\n", $out));
    }

    public function testUiProverPassesValidThemesAndCatchesLowContrastOrBudgetOverrun(): void
    {
        $zokeibi = new Zokeibi();
        $playgroundProof = $zokeibi->prover->prove(
            $this->packageDir . DIRECTORY_SEPARATOR . 'playground',
            $this->packageDir . DIRECTORY_SEPARATOR . 'playground' . DIRECTORY_SEPARATOR . 'views',
            12.5,
            true,
        );
        self::assertTrue($playgroundProof['passed']);
        self::assertSame('A+', $playgroundProof['grade']);
        self::assertSame(100, $playgroundProof['score']);
        self::assertSame(63, $playgroundProof['pillars']['budget']['components_used']);

        // Failing contrast custom theme (WCAG mid-luminance dead zone L=0.56, C=0.0) & tight budget must FAIL
        $tmpDir = $this->makeTempDir('zokeibi-prove-fail-');
        try {
            mkdir($tmpDir . DIRECTORY_SEPARATOR . 'views', 0o775, true);
            file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.kgn.html', '<x-ui.button variant="primary">Save</x-ui.button>');
            $zokeibi->studio->save($tmpDir, new ThemeTokenConfig(
                customThemes: [
                    'low-contrast-gray' => [
                        'hue' => 0.0,
                        'chroma' => 0.0,
                        'accent_lightness' => 0.56,
                        'mode' => 'dark',
                        'radius_px' => 8,
                    ],
                ],
            ));

            $badContrast = $zokeibi->prover->prove($tmpDir, $tmpDir . DIRECTORY_SEPARATOR . 'views', 6.5, true);
            self::assertFalse($badContrast['passed']);
            self::assertFalse($badContrast['pillars']['contrast']['passed']);
            self::assertNotEmpty($badContrast['pillars']['contrast']['failures']);

            $overBudget = $zokeibi->prover->prove($tmpDir, $tmpDir . DIRECTORY_SEPARATOR . 'views', 0.5, true);
            self::assertFalse($overBudget['passed']);
            self::assertFalse($overBudget['pillars']['budget']['passed']);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testCssWatcherFingerprintsAndRebuildsOnce(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-watch-');

        try {
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            mkdir($viewsDir, 0o775, true);
            $tpl = $viewsDir . DIRECTORY_SEPARATOR . 'index.kgn.html';
            file_put_contents($tpl, '<x-ui.button variant="primary">Hi</x-ui.button>');

            $h1 = $zokeibi->watcher->fingerprint($tmpDir, $viewsDir);
            file_put_contents($tpl, '<x-ui.button variant="primary">Hi</x-ui.button><x-ui.stepper steps="A|B" current="1" />');
            touch($tpl, time() + 2);
            clearstatcache(true);
            $h2 = $zokeibi->watcher->fingerprint($tmpDir, $viewsDir);
            self::assertNotSame($h1, $h2);

            $outCss = $tmpDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zokeibi.min.css';
            $logs = [];
            $report = $zokeibi->watcher->watch($tmpDir, $viewsDir, $outCss, 100, true, static function (string $line) use (&$logs): void {
                $logs[] = $line;
            });

            self::assertFileExists($outCss);
            self::assertContains('stepper', $report->usedComponents);
            self::assertNotEmpty($logs);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testV110SignaturePadThemeSwitcherTabularAndBlocks(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-v110-');

        try {
            $zokeibi->blocks->install($tmpDir, ['saas-shell', 'auth-login'], $zokeibi->installer, true);
            $zokeibi->installer->add($tmpDir, ['signature-pad'], true);

            $shell = (string) file_get_contents($tmpDir . '/views/blocks/saas-shell.kgn.html');
            self::assertStringContainsString('<x-ui.sidebar', $shell);
            self::assertStringContainsString('data-z-cycle-theme="washi,sumi,matcha"', $shell);
            self::assertStringContainsString('z-app-layout', $shell);

            $authLogin = (string) file_get_contents($tmpDir . '/views/blocks/auth-login.kgn.html');
            self::assertStringContainsString('/auth/passkeys/login/options', $authLogin);

            file_put_contents(
                $tmpDir . '/views/lease.kgn.html',
                '<main><x-ui.signature-pad name="tenant_sig" label="Tenant Signature" mode="both" required="true" /><x-ui.button variant="ghost" data-z-set-theme="washi">Light</x-ui.button></main>',
            );

            $audit = $zokeibi->auditor->auditDirectory($tmpDir . '/views');
            self::assertTrue($audit->passed, 'Expected strict audit pass: ' . json_encode($audit->findings));
            self::assertSame(100, $audit->score);

            $build = $zokeibi->compiler->buildFromDirectory($tmpDir . '/views');
            self::assertContains('signature-pad', $build->usedComponents);
            self::assertStringContainsString('font-variant-numeric:tabular-nums', $build->css);
            self::assertStringContainsString('@media print', $build->css);
            self::assertStringContainsString('.z-sig-canvas', $build->css);

            $runtime = (string) file_get_contents($this->packageDir . '/resources/js/zokeibi-runtime.js');
            self::assertStringContainsString('data-z-cycle-theme', $runtime);
            self::assertStringContainsString('data-z-sig-canvas', $runtime);
            self::assertStringContainsString('zokeibi:theme-change', $runtime);
            self::assertStringNotContainsString('.innerHTML', $runtime);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testV120DensityMoneyAlertDialogUndoToastsTablePowerSkeletonAndAiCards(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-v120-');

        try {
            $zokeibi->blocks->install($tmpDir, ['saas-shell'], $zokeibi->installer, true);
            $zokeibi->installer->add(
                $tmpDir,
                ['button', 'money-input', 'alert-dialog', 'toast', 'table', 'skeleton', 'ai-review', 'approval-card'],
                true,
            );
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            $shell = (string) file_get_contents($viewsDir . '/blocks/saas-shell.kgn.html');
            self::assertStringContainsString('data-z-cycle-density="calm,default,compact"', $shell);

            $tpl = <<<'HTML'
<main>
  <x-ui.button variant="ghost" data-z-set-density="compact">Power Mode</x-ui.button>
  <x-ui.button variant="danger" data-z-undo="Lease archived" data-z-undo-delay="5000">Archive</x-ui.button>
  <x-ui.button variant="emerald" data-z-toast="Saved" data-z-toast-desc="Ledger synced" data-z-toast-variant="emerald">Toast</x-ui.button>
  <x-ui.money-input name="rent_cents" label="Rent" value="185000" currency="USD" symbol="$" />
  <x-ui.money-input name="credit_cents" label="Credit" value="-12505" allow-negative="true" />
  <x-ui.money-input name="yen" label="JPY" value="5000" currency="JPY" symbol="¥" decimals="0" />
  <x-ui.alert-dialog id="ad-del" title="Delete Unit" description="Permanent action" confirm-text="DELETE" action="javascript:alert(1)" />
  <x-ui.toast title="Done" action-label="Undo" dismissible="true" />
  <x-ui.table caption="Units" searchable="true" sortable="true" selectable="true" column-toggle="true" exportable="true" export-filename="units.csv" bulk-actions="archive:Archive:outline|delete:Delete:danger">
    <thead><tr><th>Unit</th><th data-numeric="true">Rent</th></tr></thead>
    <tbody><tr data-row-id="u-1"><td>4B</td><td data-numeric="true">$1,850.00</td></tr></tbody>
  </x-ui.table>
  <x-ui.skeleton variant="card" />
  <x-ui.skeleton variant="kpi" live-only="true" />
  <x-ui.skeleton variant="table" rows="3" cols="4" />
  <x-ui.ai-review title="Receipt OCR" model="Gemini" source-image="javascript:alert(1)" source-excerpt="TOTAL $184.50" fields="vendor|Vendor|Home Depot|98%;;category|Category|Plumbing|62%" action="javascript:alert(1)" />
  <x-ui.approval-card title="Rent Renewal" agent="Chie AI" risk="critical" summary="Raises rent" changes="Rent|$1,800|$1,850" action="javascript:alert(1)" approval-id="apr-99" note-field="true" />
</main>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'v120.kgn.html', $tpl);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('v120', []);

            // Zero inline style, zero javascript: URLs
            self::assertStringNotContainsString('style="', $html);
            self::assertStringNotContainsString('javascript:', strtolower($html));

            // Density + Undo + Toast hooks
            self::assertStringContainsString('data-z-set-density="compact"', $html);
            self::assertStringContainsString('data-z-undo="Lease archived"', $html);
            self::assertStringContainsString('data-z-toast="Saved"', $html);

            // Money input exact integer cents + formatted display
            self::assertStringContainsString('name="rent_cents"', $html);
            self::assertStringContainsString('value="185000"', $html);
            self::assertStringContainsString('value="1,850.00"', $html);
            self::assertStringContainsString('value="-125.05"', $html);
            self::assertStringContainsString('value="5,000"', $html);

            // Alert dialog safety invariants
            self::assertStringContainsString('role="alertdialog"', $html);
            self::assertStringContainsString('data-z-static-backdrop="true"', $html);
            self::assertStringContainsString('data-z-initial-focus="true"', $html);
            self::assertStringContainsString('data-z-confirm-match="DELETE"', $html);

            // Table power suite
            self::assertStringContainsString('data-z-table-selectable="true"', $html);
            self::assertStringContainsString('data-z-table-col-toggle="true"', $html);
            self::assertStringContainsString('data-z-table-export-file="units.csv"', $html);
            self::assertStringContainsString('data-z-table-bulk-action="delete"', $html);
            self::assertStringContainsString('data-z-table-col-list', $html);

            // Skeleton composite presets + live-only visibility
            self::assertStringContainsString('z-skeleton-card', $html);
            self::assertStringContainsString('z-skeleton-kpi z-skeleton-live', $html);
            self::assertStringContainsString('z-skeleton-table', $html);

            // AI extraction review + approval gate
            self::assertStringContainsString('z-ai-field is-low', $html);
            self::assertStringContainsString('62% · Low', $html);
            self::assertStringContainsString('z-approval-critical', $html);
            self::assertStringContainsString('<del class="z-approval-old">$1,800</del>', $html);
            self::assertStringContainsString('<ins class="z-approval-new">$1,850</ins>', $html);
            self::assertStringContainsString('name="approval_id" value="apr-99"', $html);
            self::assertStringContainsString('name="reviewer_note"', $html);

            // CSS tree-shaker detects framework-agnostic classes for all 4 new components + compiles density tokens
            $detected = $zokeibi->compiler->detectComponentsInSource(
                '<div class="z-money"><div class="z-alertdialog"><div class="z-ai-review"><div class="z-approval"></div></div></div></div>',
            );
            self::assertContains('money-input', $detected);
            self::assertContains('alert-dialog', $detected);
            self::assertContains('ai-review', $detected);
            self::assertContains('approval-card', $detected);

            $build = $zokeibi->compiler->buildFromDirectory($viewsDir);
            self::assertStringContainsString('[data-z-density="compact"]', $build->css);
            self::assertStringContainsString('.z-toast-stack', $build->css);
            self::assertStringContainsString('.z-table-bulk', $build->css);
            self::assertStringContainsString('.z-skeleton-table', $build->css);
            self::assertStringContainsString('.z-ai-review-grid', $build->css);
            self::assertStringContainsString('.z-approval-diff', $build->css);

            // Strict audit on clean template passes 100/100 (replace javascript: test URLs first)
            $cleanTpl = str_replace('javascript:alert(1)', '/safe-endpoint', $tpl);
            $audit = $zokeibi->auditor->auditSource($cleanTpl, 'v120-clean.kgn.html');
            self::assertTrue($audit->passed, 'Expected v1.2.0 audit pass: ' . json_encode($audit->findings));
            self::assertSame(100, $audit->score);
            self::assertSame(0, $audit->warningCount);

            // Runtime safety & v1.2.0 hooks
            $runtime = (string) file_get_contents($this->packageDir . '/resources/js/zokeibi-runtime.js');
            self::assertStringContainsString('data-z-set-density', $runtime);
            self::assertStringContainsString('parseMoneyToCents', $runtime);
            self::assertStringContainsString('data-z-static-backdrop', $runtime);
            self::assertStringContainsString('zokeibi:undo-cancelled', $runtime);
            self::assertStringContainsString('exportTableCsv', $runtime);
            self::assertStringNotContainsString('.innerHTML', $runtime);
            self::assertStringNotContainsString('eval(', $runtime);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testV130FilterBarCompareSliderBottomSheetChartTooltipsAndDirtyGuard(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-v130-');

        try {
            $zokeibi->installer->add($tmpDir, ['filter-bar', 'compare-slider', 'drawer', 'chart', 'input', 'button'], true);
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            $tpl = <<<'HTML'
<main>
  <x-ui.filter-bar views="all:All|overdue:Overdue:javascript:alert(1)" active-view="overdue" filters="status:Status:Overdue" options="status:Status:Paid" sync-url="true" />
  <x-ui.compare-slider before-label="Before" after-label="After" before-src="javascript:alert(1)" after-text="Clean" initial="60" />
  <x-ui.drawer id="bs-1" title="Mobile Sheet" position="bottom" show="sheetOpen">Sheet content</x-ui.drawer>
  <x-ui.chart type="bar" title="Rent Collected" meta="Nov 2026" data="W1:12000|W2:18500" />
  <form action="/save" method="POST" data-z-dirty-guard="true">
    <x-ui.input name="unit_name" label="Unit Name" value="4B" />
  </form>
</main>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'v130.kgn.html', $tpl);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('v130', []);

            self::assertStringNotContainsString('style="', $html);
            self::assertStringNotContainsString('javascript:', strtolower($html));
            self::assertStringContainsString('data-z-filter-bar="true"', $html);
            self::assertStringContainsString('data-z-filter-sync-url="true"', $html);
            self::assertStringContainsString('data-z-compare="true"', $html);
            self::assertStringContainsString('data-compare-initial="60"', $html);
            self::assertStringContainsString('z-drawer-bottom', $html);
            self::assertStringContainsString('z-drawer-handle', $html);
            self::assertStringContainsString('data-z-chart-readout', $html);
            self::assertStringContainsString('data-chart-tip="W1: 12000"', $html);
            self::assertStringContainsString('data-z-dirty-guard="true"', $html);

            $detected = $zokeibi->compiler->detectComponentsInSource('<div class="z-filter-bar"><figure class="z-compare"></figure></div>');
            self::assertContains('filter-bar', $detected);
            self::assertContains('compare-slider', $detected);

            $build = $zokeibi->compiler->buildFromDirectory($viewsDir);
            self::assertStringContainsString('.z-filter-pill', $build->css);
            self::assertStringContainsString('.z-compare-divider', $build->css);
            self::assertStringContainsString('.z-drawer-bottom', $build->css);
            self::assertStringContainsString('.z-dirty-bar', $build->css);

            $cleanTpl = str_replace('javascript:alert(1)', '/safe', $tpl);
            $audit = $zokeibi->auditor->auditSource($cleanTpl, 'v130-clean.kgn.html');
            self::assertTrue($audit->passed, 'Expected v1.3.0 audit pass: ' . json_encode($audit->findings));
            self::assertSame(100, $audit->score);

            $runtime = (string) file_get_contents($this->packageDir . '/resources/js/zokeibi-runtime.js');
            self::assertStringContainsString('zokeibi:filter-change', $runtime);
            self::assertStringContainsString('--z-cmp-pos', $runtime);
            self::assertStringContainsString('data-z-dirty-guard', $runtime);
            self::assertStringContainsString('data-chart-tip', $runtime);
            self::assertStringNotContainsString('.innerHTML', $runtime);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testV140CountdownAndDataTableKeyboardFrozenServerCursor(): void
    {
        $zokeibi = new Zokeibi();
        $tmpDir = $this->makeTempDir('zokeibi-v140-');

        try {
            $zokeibi->installer->add($tmpDir, ['countdown', 'table'], true);
            $viewsDir = $tmpDir . DIRECTORY_SEPARATOR . 'views';
            $cacheDir = $tmpDir . DIRECTORY_SEPARATOR . 'cache';
            mkdir($cacheDir, 0o775, true);

            $tpl = <<<'HTML'
<main>
  <x-ui.countdown until="2026-12-01T18:00:00Z" server-now="2026-12-01T17:59:15Z" urgent-seconds="60" prefix="Closes in" />
  <x-ui.countdown seconds="120" variant="inline" />
  <x-ui.table
    caption="Auctions"
    searchable="true"
    sortable="true"
    selectable="true"
    frozen-first="true"
    keyboard="true"
    server-sort="true"
    cursor-url="/api/auctions"
    next-cursor="cur_2"
    filter-source="#auction-filters"
  >
    <thead><tr><th scope="col" data-z-sort="string" data-sort-key="domain">Domain</th><th scope="col" data-z-sort="number" data-sort-key="bid">Bid</th></tr></thead>
    <tbody><tr data-row-id="acme"><td>acme.com</td><td id="bid-acme">$2,450</td></tr></tbody>
  </x-ui.table>
</main>
HTML;
            file_put_contents($viewsDir . DIRECTORY_SEPARATOR . 'v140.kgn.html', $tpl);

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $html = $engine->render('v140', []);

            self::assertStringNotContainsString('style="', $html);
            self::assertStringContainsString('data-z-countdown="2026-12-01T18:00:00Z"', $html);
            self::assertStringContainsString('data-z-server-now="2026-12-01T17:59:15Z"', $html);
            self::assertStringContainsString('is-urgent', $html);
            self::assertStringContainsString('data-z-countdown-seconds="120"', $html);
            self::assertStringContainsString('z-table-frozen', $html);
            self::assertStringContainsString('z-table-has-select', $html);
            self::assertStringContainsString('data-z-table-kbd="true"', $html);
            self::assertStringContainsString('data-z-table-server="true"', $html);
            self::assertStringContainsString('data-z-table-cursor-url="/api/auctions"', $html);
            self::assertStringContainsString('data-z-table-filter-source="#auction-filters"', $html);
            self::assertStringContainsString('data-z-table-sentinel', $html);
            self::assertStringContainsString('data-next-cursor="cur_2"', $html);

            $detected = $zokeibi->compiler->detectComponentsInSource('<time class="z-countdown" data-z-countdown="2026-12-01T18:00:00Z">00:45</time>');
            self::assertContains('countdown', $detected);

            $build = $zokeibi->compiler->buildFromDirectory($viewsDir);
            self::assertStringContainsString('.z-countdown-pill', $build->css);
            self::assertStringContainsString('.kgn-patch-flash', $build->css);
            self::assertStringContainsString('.z-table-frozen', $build->css);
            self::assertStringContainsString('.is-kbd-active', $build->css);

            $audit = $zokeibi->auditor->auditSource($tpl, 'v140.kgn.html');
            self::assertTrue($audit->passed, 'Expected v1.4.0 audit pass: ' . json_encode($audit->findings));
            self::assertSame(100, $audit->score);

            $runtime = (string) file_get_contents($this->packageDir . '/resources/js/zokeibi-runtime.js');
            self::assertStringContainsString('initCountdowns', $runtime);
            self::assertStringContainsString('resolveCountdownMeta', $runtime);
            self::assertStringContainsString('kongono:sse-patch', $runtime);
            self::assertStringContainsString('data-z-server-now', $runtime);
            self::assertStringContainsString('zokeibi:countdown-ended', $runtime);
            self::assertStringContainsString('data-z-table-kbd', $runtime);
            self::assertStringContainsString('data-active-sort', $runtime);
            self::assertStringContainsString('data-z-table-filter-source', $runtime);
            self::assertStringContainsString('zokeibi:table-sort', $runtime);
            self::assertStringContainsString('zokeibi:table-row-open', $runtime);
            self::assertStringContainsString('zokeibi:table-load-more', $runtime);
            self::assertStringNotContainsString('.innerHTML', $runtime);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testKibannoAdaptiveNativeChromeAndSafeAreaHooksCompiledIntoCss(): void
    {
        $zokeibi = new Zokeibi();
        $report = $zokeibi->compiler->buildForComponents(['button']);
        self::assertStringContainsString('--z-safe-top', $report->css);
        self::assertStringContainsString('data-kibanno-chrome', $report->css);
        self::assertStringContainsString('.z-web-only', $report->css);
        self::assertStringContainsString('.z-native-only', $report->css);
        self::assertStringContainsString('.z-safe-pad', $report->css);
    }

    private function makeTempDir(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(5));
        mkdir($path, 0775, true);

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
