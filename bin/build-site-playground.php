#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the static, zero-backend, strict-CSP 60-Component Interactive Playground
 * for https://zokeibi.com/playground/ directly from the real Zokeibi Playground app.
 */

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Kongono\Http\App;
use Kongono\Http\Request;
use Zokeibi\Zokeibi;

$zokeibi = new Zokeibi($root . DIRECTORY_SEPARATOR . 'resources');

// 1. Rebuild compiled CSS from playground views first so telemetry & CSS are 100% fresh
$playgroundCssOut = $root . '/playground/public/assets/zokeibi.min.css';
$zokeibi->compiler->buildFromDirectory($root . '/playground/views', $playgroundCssOut);

// 2. Boot the real playground app and capture all live outputs
/** @var App $app */
$app = require $root . '/playground/app/bootstrap.php';

$homeRes = $app->handle(Request::create('GET', '/'));
if (200 !== $homeRes->getStatusCode()) {
    fwrite(STDERR, "Failed rendering playground home: HTTP {$homeRes->getStatusCode()}\n");
    exit(1);
}
$html = (string) $homeRes->getBody();

$liveJsRes = $app->handle(Request::create('GET', '/_kongono/live.js'));
if (200 !== $liveJsRes->getStatusCode()) {
    fwrite(STDERR, "Failed fetching Kongono live.js: HTTP {$liveJsRes->getStatusCode()}\n");
    exit(1);
}
$liveJs = (string) $liveJsRes->getBody();

$registryRes = $app->handle(Request::create('GET', '/api/registry.json'));
$registryJson = (string) $registryRes->getBody();

$proveRes = $app->handle(Request::create('GET', '/studio/prove'));
$proveJson = (string) $proveRes->getBody();

// 3. Transform HTML cleanly for static hosting at https://zokeibi.com/playground/
// 3a. Head metadata + static script paths (zero inline JS/CSS)
$seoHead = <<<'HTML'
  <meta name="description" content="Interactive 60-component live playground for Zokeibi (造形美) — test OKLCH themes, Two-Gear density, integer-cents money inputs, bulk/CSV DataTables, hardened alert dialogs, undoable toasts, signature pads, pure-SVG charts, and AI workflow cards under strict CSP.">
  <link rel="canonical" href="https://zokeibi.com/playground/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="Zokeibi (造形美) — Live 60-Component Interactive Playground">
  <meta property="og:description" content="Try all 60 strict-CSP Zokeibi components live with instant OKLCH theme, radius, and Two-Gear density switching.">
  <meta property="og:url" content="https://zokeibi.com/playground/">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="stylesheet" href="/assets/zokeibi.min.css">
  <link rel="stylesheet" href="/assets/playground.css">
  <script src="/assets/live.js" defer></script>
  <script src="/assets/zokeibi-runtime.js" defer></script>
  <script src="/assets/playground-extras.js" defer></script>
HTML;

$html = preg_replace(
    '/<link rel="icon"[\s\S]*?<script src="\/assets\/zokeibi-runtime\.js[^"]*" defer><\/script>/',
    $seoHead,
    $html,
    1,
) ?? $html;

$html = str_replace(
    '<title>Zokeibi 造形美 — Zero-npm OKLCH UI Framework for Kongono</title>',
    '<title>Zokeibi (造形美) — Live 60-Component Interactive Playground</title>',
    $html,
);

// 3b. Add data-z-density="default" on <html> so zokeibi-runtime.js persists density across / and /playground/
$html = str_replace('<html lang="en">', '<html lang="en" data-z-density="default">', $html);

// 3c. Rewrite navigation bar & footer for zokeibi.com static routes (zero 404s)
$verEsc = htmlspecialchars($zokeibi->version(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$staticHeader = <<<HTML
  <header class="pg-nav">
    <div class="pg-nav-inner">
      <a class="pg-brand" href="/">
        <span class="pg-mark" aria-hidden="true">造</span>
        <span class="pg-brand-name">Zokeibi</span>
        <span class="pg-brand-sub">zokeibi.com</span>
      </a>
      <nav class="pg-links" aria-label="Primary navigation">
        <a class="pg-link" href="/">Overview</a>
        <a class="pg-link is-active" href="/playground/" aria-current="page">60-Component Playground</a>
        <a class="pg-link" href="#tree-shaker">Tree-Shaker</a>
        <a class="pg-link" href="/api/prove.json">UI Proof JSON</a>
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

$html = preg_replace('/<header class="pg-nav">[\s\S]*?<\/header>/', $staticHeader, $html, 1) ?? $html;

$staticFooter = <<<HTML
  <footer class="pg-footer z-shell">
    <div>
      <strong>Zokeibi (造形美 — "Beauty of Form") v{$verEsc}</strong> · Pure PHP 8.5 CSS compiler &amp; 60 accessible <code>&lt;x-ui.*&gt;</code> components.
    </div>
    <div class="z-row">
      <a class="pg-link" href="/">Overview</a>
      <a class="pg-link" href="/api/registry.json">/api/registry.json</a>
      <a class="pg-link" href="/api/prove.json">/api/prove.json</a>
      <a class="pg-link" href="/llms.txt">llms.txt</a>
      <a class="pg-link" href="https://github.com/kongono/zokeibi" target="_blank" rel="noopener">GitHub</a>
    </div>
  </footer>
HTML;

$html = preg_replace('/<footer class="pg-footer z-shell">[\s\S]*?<\/footer>/', $staticFooter, $html, 1) ?? $html;

// 3d. Rewrite local-only links inside hero & cmdk to real zokeibi.com routes
$html = str_replace(
    [
        'href="/studio">Open Token Studio GUI</a>',
        'href="/studio/prove"',
        'href="/studio"',
        'href="/api/v1/health"',
    ],
    [
        'href="/#quickstart">Install &amp; Run Local Studio</a>',
        'href="/api/prove.json"',
        'href="/#quickstart"',
        'href="/api/prove.json"',
    ],
    $html,
);

// 3e. Inject instant search toolbar right above the 60-component grid
$searchBar = <<<'HTML'
    <div class="pg-search-bar" role="search" aria-label="Search Zokeibi components">
      <input
        id="pg-comp-search"
        type="search"
        class="z-input pg-search-input"
        placeholder="Search all 60 components (e.g. money, table, alert, ai, chart, popover, signature)..."
        aria-label="Search all 60 components"
        data-pg-search-input
      >
      <span class="z-mono z-soft pg-search-count" data-pg-search-count aria-live="polite">60 of 60 components</span>
    </div>

    <div class="z-grid-2" data-pg-grid>
HTML;

$html = preg_replace(
    '/(<section id="components">[\s\S]*?<\/div>\s*<\/div>\s*)<div class="z-grid-2">/',
    '$1' . $searchBar,
    $html,
    1,
) ?? $html;

// 3f. Tag every component wrapper with deterministic id="comp-<id>" + data-pg-card="<id>" for deep-links & instant search
$html = preg_replace_callback(
    '/<div (k-show="cat === \'all\' \|\| cat === \'([a-z]+)\'")>([\s\S]*?<div class="pg-demo-foot"><span>&lt;x-ui\.([a-z0-9-]+)&gt;<\/span>[\s\S]*?<\/section>\s*)<\/div>/',
    static function (array $m): string {
        $catShow = $m[1];
        $cat = $m[2];
        $inner = $m[3];
        $compId = $m[4];

        $title = '';
        if (1 === preg_match('/<h3 class="pg-demo-title">([^<]+)/', $inner, $tm)) {
            $title = trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $desc = '';
        if (1 === preg_match('/<p class="pg-demo-desc">([^<]+)/', $inner, $dm)) {
            $desc = trim(html_entity_decode($dm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
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
    $html,
) ?? $html;

// 3g. Add clean 1-click Copy buttons inside Markup/CLI <pre class="z-code"> blocks (handled by playground-extras.js)
$html = preg_replace(
    '/(<pre\b[^>]*class="z-code"[^>]*>)/',
    '<div class="pg-code-wrap" data-pg-code-wrap>$1',
    $html,
) ?? $html;
$html = preg_replace(
    '/(<div class="pg-code-wrap" data-pg-code-wrap><pre\b[^>]*class="z-code"[^>]*>[\s\S]*?<\/pre>)/',
    '$1</div>',
    $html,
) ?? $html;

// 4. Write all generated static assets into site/
$dirs = [
    $root . '/site/playground',
    $root . '/site/api',
    $root . '/site/assets',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create directory: {$dir}\n");
        exit(1);
    }
}

file_put_contents($root . '/site/playground/index.html', $html);
file_put_contents($root . '/site/assets/live.js', $liveJs);
file_put_contents($root . '/site/assets/playground.css', (string) file_get_contents($root . '/playground/public/assets/playground.css'));
file_put_contents($root . '/site/assets/zokeibi.min.css', (string) file_get_contents($playgroundCssOut));
file_put_contents($root . '/site/assets/zokeibi-runtime.js', (string) file_get_contents($root . '/resources/js/zokeibi-runtime.js'));
file_put_contents($root . '/site/api/registry.json', $registryJson);
file_put_contents($root . '/site/api/prove.json', $proveJson);

$cardCount = substr_count($html, 'data-pg-card="');
echo sprintf(
    "Built static Zokeibi Playground -> site/playground/index.html (%d live components, %s KB HTML, %s KB gzip live.js)\n",
    $cardCount,
    number_format(strlen($html) / 1024, 1),
    number_format(strlen((string) gzencode($liveJs, 9)) / 1024, 1),
);
