#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the static, zero-backend, strict-CSP Zokeibi website artifacts:
 *   - site/playground/index.html (All 62 live components + All 8 live SaaS blocks)
 *   - site/studio/index.html     (Live client-side OKLCH Design Token Studio)
 *   - site/api/registry.json     (Machine-readable 62-component + 8-block registry)
 *   - site/api/prove.json        (Live Grade A+ zokeibi prove Readiness Certificate)
 *   - Content-hashed `?v=<8-char>` cache-busting across site/index.html, playground, and studio
 */

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Kongono\Http\App;
use Kongono\Http\Request;
use Kongono\View\ViewEngine;
use Zokeibi\Zokeibi;

$zokeibi = new Zokeibi($root . DIRECTORY_SEPARATOR . 'resources');
$verEsc = htmlspecialchars($zokeibi->version(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

// 1. Rebuild compiled CSS from playground views so telemetry & CSS are 100% fresh
$playgroundCssOut = $root . '/playground/public/assets/zokeibi.min.css';
$zokeibi->compiler->buildFromDirectory($root . '/playground/views', $playgroundCssOut);

// 2. Boot the real playground app and capture live rendered responses
/** @var App $app */
$app = require $root . '/playground/app/bootstrap.php';

$homeRes = $app->handle(Request::create('GET', '/'));
if (200 !== $homeRes->getStatusCode()) {
    fwrite(STDERR, "Failed rendering playground home: HTTP {$homeRes->getStatusCode()}\n");
    exit(1);
}
$pgHtml = (string) $homeRes->getBody();

$studioRes = $app->handle(Request::create('GET', '/studio'));
if (200 !== $studioRes->getStatusCode()) {
    fwrite(STDERR, "Failed rendering studio: HTTP {$studioRes->getStatusCode()}\n");
    exit(1);
}
$studioHtml = (string) $studioRes->getBody();

$liveJsRes = $app->handle(Request::create('GET', '/_kongono/live.js'));
if (200 !== $liveJsRes->getStatusCode()) {
    fwrite(STDERR, "Failed fetching Kongono live.js: HTTP {$liveJsRes->getStatusCode()}\n");
    exit(1);
}
$liveJs = (string) $liveJsRes->getBody();

$registryJson = (string) $app->handle(Request::create('GET', '/api/registry.json'))->getBody();
$proveJson = (string) $app->handle(Request::create('GET', '/studio/prove'))->getBody();

// 3. Publish core static assets first so we can compute exact SHA-256 cache-busting hashes
foreach (['/site/playground', '/site/studio', '/site/api', '/site/assets'] as $relDir) {
    $dir = $root . $relDir;
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create directory: {$dir}\n");
        exit(1);
    }
}

file_put_contents($root . '/site/assets/live.js', $liveJs);
file_put_contents($root . '/site/assets/playground.css', (string) file_get_contents($root . '/playground/public/assets/playground.css'));
file_put_contents($root . '/site/assets/zokeibi.min.css', (string) file_get_contents($playgroundCssOut));
file_put_contents($root . '/site/assets/zokeibi-runtime.js', (string) file_get_contents($root . '/resources/js/zokeibi-runtime.js'));
file_put_contents($root . '/site/api/registry.json', $registryJson);
file_put_contents($root . '/site/api/prove.json', $proveJson);

$assetHash = static function (string $file) use ($root): string {
    $path = $root . '/site/assets/' . $file;
    return is_file($path) ? substr((string) hash_file('sha256', $path), 0, 8) : '1';
};

$hCss = $assetHash('zokeibi.min.css');
$hPgCss = $assetHash('playground.css');
$hSiteCss = $assetHash('site.css');
$hSiteJs = $assetHash('site.js');
$hLive = $assetHash('live.js');
$hRt = $assetHash('zokeibi-runtime.js');
$hPgExt = $assetHash('playground-extras.js');
$hStudioJs = $assetHash('studio-static.js');

$buildNav = static function (string $active) use ($verEsc, $zokeibi): string {
    $count = $zokeibi->registry->count();
    $pgAct = 'playground' === $active ? ' is-active' : '';
    $pgCur = 'playground' === $active ? ' aria-current="page"' : '';
    $stAct = 'studio' === $active ? ' is-active' : '';
    $stCur = 'studio' === $active ? ' aria-current="page"' : '';
    return <<<HTML
  <header class="pg-nav">
    <div class="pg-nav-inner">
      <a class="pg-brand" href="/">
        <span class="pg-mark" aria-hidden="true">造</span>
        <span class="pg-brand-name">Zokeibi</span>
        <span class="pg-brand-sub">zokeibi.com</span>
      </a>
      <nav class="pg-links" aria-label="Primary navigation">
        <a class="pg-link" href="/">Overview</a>
        <a class="pg-link{$pgAct}" href="/playground/"{$pgCur}>{$count} Components</a>
        <a class="pg-link" href="/playground/#blocks">8 SaaS Blocks</a>
        <a class="pg-link{$stAct}" href="/studio/"{$stCur}>Live OKLCH Studio</a>
        <a class="pg-link" href="/api/prove.json">Proof JSON</a>
        <a class="pg-link" href="/api/registry.json">Registry JSON</a>
        <a class="pg-link" href="https://github.com/kongono/zokeibi" target="_blank" rel="noopener">GitHub</a>
        <a class="pg-link" href="https://kongono.com" target="_blank" rel="noopener">Kongono →</a>
      </nav>
      <div class="z-row">
        <span class="z-badge z-badge-emerald"><span class="z-badge-dot" aria-hidden="true"></span>Strict CSP · 0 npm</span>
        <span class="z-badge z-badge-primary">v{$verEsc}</span>
      </div>
    </div>
  </header>
HTML;
};

$staticFooter = <<<HTML
  <footer class="pg-footer z-shell">
    <div>
      <strong>Zokeibi (造形美 — "Beauty of Form") v{$verEsc}</strong> · Pure PHP 8.5 CSS compiler, {$zokeibi->registry->count()} accessible <code>&lt;x-ui.*&gt;</code> components &amp; 8 SaaS blocks.
    </div>
    <div class="z-row">
      <a class="pg-link" href="/">Overview</a>
      <a class="pg-link" href="/playground/">Playground</a>
      <a class="pg-link" href="/studio/">OKLCH Studio</a>
      <a class="pg-link" href="/api/registry.json">/api/registry.json</a>
      <a class="pg-link" href="/api/prove.json">/api/prove.json</a>
      <a class="pg-link" href="/llms.txt">llms.txt</a>
    </div>
  </footer>
HTML;

// 4. Render all 8 live turnkey SaaS blocks into interactive Preview / Markup / CLI cards
$tmpBlocksDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zokeibi-site-blocks-' . bin2hex(random_bytes(4));
mkdir($tmpBlocksDir . DIRECTORY_SEPARATOR . 'cache', 0o775, true);
$zokeibi->blocks->install($tmpBlocksDir, $zokeibi->blocks->ids(), $zokeibi->installer, true);
$blockEngine = new ViewEngine($tmpBlocksDir . DIRECTORY_SEPARATOR . 'views', $tmpBlocksDir . DIRECTORY_SEPARATOR . 'cache');

$blockDemoData = [
    'appName' => 'RentalGate Studio',
    'userInitials' => 'AI',
    'userName' => 'Anthony Iovanna',
    'csrf' => 'demo-csrf-token',
    'old' => ['email' => 'founder@acme.dev'],
    'planName' => 'Pro Scale',
    'workspaceName' => 'Acme Properties LLC',
    'seatsUsed' => '11 / 15',
    'nextAmount' => '$49.00',
    'region' => 'us-east',
    'step' => 2,
    'title' => '404 — Page Not Found',
    'statusCode' => '404',
    'reference' => 'KGN-9F4A-2026',
    'message' => 'The requested workspace route does not exist. Share the reference ID above if you need support.',
    'mrr' => '$48,920',
    'tenants' => '1,284',
    'p95' => '11.4 ms',
    'maskedToken' => 'kgn_pat_live_9f4a8c2e1b7d',
];

$blocksHtmlParts = [
    '<section id="blocks">',
    '  <div class="pg-section-head">',
    '    <div>',
    '      <h2 class="pg-section-title">All 8 Live Turnkey SaaS Blocks</h2>',
    '      <p class="pg-section-sub">Full-page layouts installed directly into your codebase via <code>php vendor/bin/zokeibi block add &lt;name&gt;</code> — zero vendor lock-in, 100/100 strict CSP.</p>',
    '    </div>',
    '    <span class="z-badge z-badge-primary">zokeibi block add --all</span>',
    '  </div>',
    '  <div class="z-stack">',
];

foreach ($zokeibi->blocks->ids() as $blockId) {
    $block = $zokeibi->blocks->get($blockId);
    $renderedBlock = $blockEngine->render('blocks/' . $blockId, $blockDemoData);
    // Neutralize demo form actions so clicking buttons inside static block previews fires the playground demo toast
    $renderedBlock = preg_replace('/action="\/[^"]*"/', 'action="#"', $renderedBlock) ?? $renderedBlock;
    $renderedBlock = preg_replace('/\bkgn-post="\/[^"]*"/', '', $renderedBlock) ?? $renderedBlock;
    $renderedBlock = preg_replace('/\bkgn-get="\/[^"]*"/', '', $renderedBlock) ?? $renderedBlock;
    // Scope unique drawer/modal IDs inside block previews so they never collide with component cards
    $renderedBlock = str_replace('id="shell-cmdk"', 'id="block-shell-cmdk"', $renderedBlock);
    $renderedBlock = str_replace('#shell-cmdk', '#block-shell-cmdk', $renderedBlock);

    $bName = htmlspecialchars($block['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bCat = htmlspecialchars(ucfirst($block['category']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bDesc = htmlspecialchars($block['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bComps = htmlspecialchars(implode(' · ', $block['components']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bCode = htmlspecialchars($block['template'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bCli = htmlspecialchars('php vendor/bin/zokeibi block add ' . $blockId, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $blocksHtmlParts[] = <<<HTML
    <section id="block-{$blockId}" class="pg-demo-card" data-pg-block="{$blockId}" k-state="{&quot;view&quot;:&quot;preview&quot;}">
      <div class="pg-demo-head">
        <div>
          <h3 class="pg-demo-title">{$bName} <span class="z-badge z-badge-primary">{$bCat}</span></h3>
          <p class="pg-demo-desc">{$bDesc}</p>
        </div>
        <div class="pg-seg">
          <button type="button" class="pg-seg-btn is-active" k-class:is-active="view === 'preview'" k-on:click="set(view, 'preview')">Live Preview</button>
          <button type="button" class="pg-seg-btn" k-class:is-active="view === 'code'" k-on:click="set(view, 'code')">Markup</button>
          <button type="button" class="pg-seg-btn" k-class:is-active="view === 'cli'" k-on:click="set(view, 'cli')">CLI</button>
        </div>
      </div>
      <div class="pg-demo-body">
        <div class="pg-stage" k-show="view === 'preview'">
          {$renderedBlock}
        </div>
        <div class="pg-code-wrap" data-pg-code-wrap><pre class="z-code" k-show="view === 'code'" hidden>{$bCode}</pre></div>
        <div class="pg-code-wrap" data-pg-code-wrap><pre class="z-code" k-show="view === 'cli'" hidden>{$bCli}</pre></div>
      </div>
      <div class="pg-demo-foot"><span>views/blocks/{$blockId}.kgn.html</span><span>Uses: {$bComps}</span></div>
    </section>
HTML;
}

$blocksHtmlParts[] = '  </div>';
$blocksHtmlParts[] = '</section>';
$blocksSectionHtml = implode("\n", $blocksHtmlParts);

// Clean up temporary block render directory
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpBlocksDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($it as $item) {
    if ($item instanceof SplFileInfo) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
}
@rmdir($tmpBlocksDir);

// 5. Transform Playground HTML -> site/playground/index.html
$pgHead = <<<HTML
  <meta name="description" content="Interactive {$zokeibi->registry->count()}-component &amp; 8-block live playground for Zokeibi (造形美) — test OKLCH themes, Two-Gear density, integer-cents money inputs, bulk/CSV DataTables, filter bars, compare sliders, dirty-form guards, alert dialogs, undoable toasts, signature pads, pure-SVG charts, and AI workflow cards under strict CSP.">
  <link rel="canonical" href="https://zokeibi.com/playground/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Zokeibi (造形美) — Live {$zokeibi->registry->count()}-Component &amp; 8-Block Playground">
  <meta property="og:description" content="Try all {$zokeibi->registry->count()} strict-CSP Zokeibi components and 8 full-page SaaS blocks live with instant OKLCH theme, radius, and Two-Gear density switching.">
  <meta property="og:url" content="https://zokeibi.com/playground/">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="stylesheet" href="/assets/zokeibi.min.css?v={$hCss}">
  <link rel="stylesheet" href="/assets/playground.css?v={$hPgCss}">
  <script src="/assets/live.js?v={$hLive}" defer></script>
  <script src="/assets/zokeibi-runtime.js?v={$hRt}" defer></script>
  <script src="/assets/playground-extras.js?v={$hPgExt}" defer></script>
HTML;

$pgHtml = preg_replace('/<link rel="icon"[\s\S]*?<script src="\/assets\/zokeibi-runtime\.js[^"]*" defer><\/script>/', $pgHead, $pgHtml, 1) ?? $pgHtml;
$pgHtml = str_replace('<title>Zokeibi 造形美 — Zero-npm OKLCH UI Framework for Kongono</title>', '<title>Zokeibi (造形美) — Live ' . $zokeibi->registry->count() . '-Component &amp; 8-Block Playground</title>', $pgHtml);
$pgHtml = str_replace('<html lang="en">', '<html lang="en" data-z-density="default">', $pgHtml);
$pgHtml = preg_replace('/<header class="pg-nav">[\s\S]*?<\/header>/', $buildNav('playground'), $pgHtml, 1) ?? $pgHtml;
$pgHtml = preg_replace('/<footer class="pg-footer z-shell">[\s\S]*?<\/footer>/', $staticFooter, $pgHtml, 1) ?? $pgHtml;
$pgHtml = str_replace(
    ['href="/studio/prove"', 'href="/studio"', 'href="/api/v1/health"'],
    ['href="/api/prove.json"', 'href="/studio/"', 'href="/api/prove.json"'],
    $pgHtml,
);

$searchBar = <<<HTML
    <div class="pg-search-bar" role="search" aria-label="Search Zokeibi components">
      <input
        id="pg-comp-search"
        type="search"
        class="z-input pg-search-input"
        placeholder="Search all {$zokeibi->registry->count()} components (e.g. money, filter, compare, table, alert, ai, chart, sheet)..."
        aria-label="Search all {$zokeibi->registry->count()} components"
        data-pg-search-input
      >
      <div class="z-row">
        <span class="z-mono z-soft pg-search-count" data-pg-search-count aria-live="polite">{$zokeibi->registry->count()} of {$zokeibi->registry->count()} components</span>
        <a class="z-btn z-btn-outline z-btn-sm" href="#blocks">Jump to 8 SaaS Blocks ↓</a>
      </div>
    </div>

    <div class="z-grid-2" data-pg-grid>
HTML;

$pgHtml = preg_replace(
    '/(<section id="components">[\s\S]*?<\/div>\s*<\/div>\s*)<div class="z-grid-2">/',
    '$1' . $searchBar,
    $pgHtml,
    1,
) ?? $pgHtml;

$pgHtml = preg_replace_callback(
    '/<div (k-show="cat === \'all\' \|\| cat === \'([a-z]+)\'")>([\s\S]*?<div class="pg-demo-foot"><span>&lt;x-ui\.([a-z0-9-]+)&gt;<\/span>[\s\S]*?<\/section>\s*)<\/div>/',
    static function (array $m): string {
        $catShow = $m[1];
        $cat = $m[2];
        $inner = $m[3];
        $compId = $m[4];

        $title = 1 === preg_match('/<h3 class="pg-demo-title">([^<]+)/', $inner, $tm)
            ? trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';
        $desc = 1 === preg_match('/<p class="pg-demo-desc">([^<]+)/', $inner, $dm)
            ? trim(html_entity_decode($dm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';
        $haystack = htmlspecialchars(strtolower(trim($compId . ' ' . $cat . ' ' . $title . ' ' . $desc)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return sprintf(
            '<div id="comp-%s" data-pg-card="%s" data-pg-cat="%s" data-pg-haystack="%s" %s>%s</div>',
            $compId,
            $compId,
            $cat,
            $haystack,
            $catShow,
            $inner,
        );
    },
    $pgHtml,
) ?? $pgHtml;

// Wrap component <pre class="z-code"> blocks with 1-click Copy wrappers before appending blocks section
$pgHtml = preg_replace('/(<pre\b[^>]*class="z-code"[^>]*>)/', '<div class="pg-code-wrap" data-pg-code-wrap>$1', $pgHtml) ?? $pgHtml;
$pgHtml = preg_replace('/(<div class="pg-code-wrap" data-pg-code-wrap><pre\b[^>]*class="z-code"[^>]*>[\s\S]*?<\/pre>)/', '$1</div>', $pgHtml) ?? $pgHtml;

// Append the live 8 SaaS Blocks section right before </main>
$pgHtml = str_replace('  </main>', "\n" . $blocksSectionHtml . "\n  </main>", $pgHtml);
file_put_contents($root . '/site/playground/index.html', $pgHtml);

// 6. Transform Studio HTML -> site/studio/index.html (live browser OKLCH workbench)
$studioHead = <<<HTML
  <meta name="description" content="Interactive browser OKLCH Design Token &amp; Theme Studio for Zokeibi (造形美) — drag 1-knob OKLCH hue, chroma, lightness, and radius sliders with real-time WCAG 2.1 AA contrast proofs and live CSS, zokeibi.json, W3C DTCG, and Tailwind v4 @theme exports.">
  <link rel="canonical" href="https://zokeibi.com/studio/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Zokeibi (造形美) — Live OKLCH Design Token &amp; Theme Studio">
  <meta property="og:description" content="Build accessible OKLCH themes with live WCAG 2.1 AA contrast checks and instant CSS, Tailwind v4, W3C DTCG, and zokeibi.json exports.">
  <meta property="og:url" content="https://zokeibi.com/studio/">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="stylesheet" href="/assets/zokeibi.min.css?v={$hCss}">
  <link rel="stylesheet" href="/assets/playground.css?v={$hPgCss}">
  <script src="/assets/live.js?v={$hLive}" defer></script>
  <script src="/assets/zokeibi-runtime.js?v={$hRt}" defer></script>
  <script src="/assets/studio-static.js?v={$hStudioJs}" defer></script>
HTML;

$studioHtml = preg_replace('/<link rel="icon"[\s\S]*?<script src="\/assets\/studio\.js[^"]*" defer><\/script>/', $studioHead, $studioHtml, 1) ?? $studioHtml;
$studioHtml = str_replace('<title>Zokeibi Studio 造形美 — Central Design Token &amp; Theme GUI</title>', '<title>Zokeibi (造形美) — Live OKLCH Theme &amp; Token Studio</title>', $studioHtml);
$studioHtml = str_replace('<html lang="en">', '<html lang="en" data-z-density="default">', $studioHtml);
$studioHtml = preg_replace('/<header class="pg-nav">[\s\S]*?<\/header>/', $buildNav('studio'), $studioHtml, 1) ?? $studioHtml;
$studioHtml = preg_replace('/<footer class="pg-footer z-shell">[\s\S]*?<\/footer>/', $staticFooter, $studioHtml, 1) ?? $studioHtml;
$studioHtml = str_replace(
    [
        'href="/studio/export?format=css"',
        'href="/studio/export?format=w3c-tokens"',
        'href="/studio/export?format=tailwind"',
        'href="/studio/prove"',
        'action="/studio/save"',
        'action="/studio/reset"',
        'action="/studio/themes/add"',
        'action="/studio/colors/add"',
        'Save to zokeibi.json &amp; Rebuild CSS',
        'Add Color Token &amp; Rebuild',
        'Live Hot-Swap Sync (auto-rebuild CSS on drag)',
        'Manual save',
    ],
    [
        'href="#studio-css-preview"',
        'href="#studio-w3c-preview"',
        'href="#studio-tailwind-preview"',
        'href="/api/prove.json"',
        'action="#"',
        'action="#"',
        'action="#"',
        'action="#"',
        'Save Snapshot in Browser',
        'Add Color Token',
        'Browser Live Preview Active (60fps OKLCH)',
        'Instant export',
    ],
    $studioHtml,
);
// Strip ephemeral server-side CSRF tokens from static Studio HTML and check the Live Preview switch
$studioHtml = preg_replace('/\s*<input type="hidden" name="_csrf" value="[^"]*">/', '', $studioHtml) ?? $studioHtml;
$studioHtml = preg_replace('/(<input\b[^>]*id="st-live-sync"[^>]*?)>/', '$1 checked>', $studioHtml, 1) ?? $studioHtml;

// Give the saved-theme and custom-color count badges stable IDs so studio-static.js updates them live
$studioHtml = preg_replace(
    '/(Saved Custom Themes<\/h3>\s*<\/div>\s*<span class="z-badge z-badge-emerald")>[^<]*<\/span>/',
    '$1 id="st-static-themes-count">0 saved</span>',
    $studioHtml,
    1,
) ?? $studioHtml;
$studioHtml = preg_replace(
    '/(Custom Brand Color Tokens<\/h3>\s*<\/div>\s*<span class="z-badge z-badge-blue")>[^<]*<\/span>/',
    '$1 id="st-static-colors-count">0 tokens</span>',
    $studioHtml,
    1,
) ?? $studioHtml;

// Replace the server-rendered custom themes & custom colors lists with client-managed containers
$studioHtml = preg_replace(
    '/(id="st-add-theme-btn"[\s\S]*?<\/form>\s*)([\s\S]*?)(<\/div>\s*<\/div>\s*<\/article>)/',
    '$1<div class="z-stack-sm" id="st-static-themes"></div>$3',
    $studioHtml,
    1,
) ?? $studioHtml;
$studioHtml = preg_replace(
    '/(id="st-add-color-btn"[\s\S]*?<\/form>\s*)([\s\S]*?)(<\/div>\s*<\/div>\s*<\/article>)/',
    '$1<div class="z-stack-sm" id="st-static-colors"></div>$3',
    $studioHtml,
    1,
) ?? $studioHtml;

file_put_contents($root . '/site/studio/index.html', $studioHtml);

// 7. Stamp automatic content-hashed `?v=<hash>` cache-busters onto site/index.html too
$siteIndexPath = $root . '/site/index.html';
$siteIndex = (string) file_get_contents($siteIndexPath);
$siteIndex = preg_replace('/href="assets\/zokeibi\.min\.css(?:\?v=[a-f0-9]+)?"/', 'href="assets/zokeibi.min.css?v=' . $hCss . '"', $siteIndex) ?? $siteIndex;
$siteIndex = preg_replace('/href="assets\/site\.css(?:\?v=[a-f0-9]+)?"/', 'href="assets/site.css?v=' . $hSiteCss . '"', $siteIndex) ?? $siteIndex;
$siteIndex = preg_replace('/src="assets\/zokeibi-runtime\.js(?:\?v=[a-f0-9]+)?"/', 'src="assets/zokeibi-runtime.js?v=' . $hRt . '"', $siteIndex) ?? $siteIndex;
$siteIndex = preg_replace('/src="assets\/site\.js(?:\?v=[a-f0-9]+)?"/', 'src="assets/site.js?v=' . $hSiteJs . '"', $siteIndex) ?? $siteIndex;
file_put_contents($siteIndexPath, $siteIndex);

$cardCount = substr_count($pgHtml, 'data-pg-card="');
$blockCount = substr_count($pgHtml, 'data-pg-block="');
echo sprintf(
    "Built Zokeibi static web suite:\n  - site/playground/index.html (%d live components + %d live SaaS blocks, %s KB)\n  - site/studio/index.html (%s KB, live OKLCH + WCAG AA + 4 exports)\n  - Cache-busted assets: css=%s rt=%s studio=%s\n",
    $cardCount,
    $blockCount,
    number_format(strlen($pgHtml) / 1024, 1),
    number_format(strlen($studioHtml) / 1024, 1),
    $hCss,
    $hRt,
    $hStudioJs,
);
