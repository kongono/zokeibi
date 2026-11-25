<?php

declare(strict_types=1);

namespace Zokeibi\Blocks;

use InvalidArgumentException;
use Zokeibi\Installer\ComponentInstaller;
use Zokeibi\Installer\InstallReport;
use RuntimeException;

/**
 * Turnkey, strict-CSP, OKLCH-themed full-page SaaS blocks (`zokeibi block list|add <name>`).
 *
 * Every block installs into `views/blocks/<id>.kgn.html` (user-owned, zero vendor lock-in),
 * installs its required `<x-ui.*>` primitives automatically, and passes `UiAuditor` at 100/100.
 */
final readonly class BlockCatalog
{
    /**
     * @return list<array{id: string, name: string, category: string, description: string, components: list<string>}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->definitions() as $id => $def) {
            $out[] = [
                'id' => $id,
                'name' => $def['name'],
                'category' => $def['category'],
                'description' => $def['description'],
                'components' => $def['components'],
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->definitions());
    }

    public function has(string $id): bool
    {
        return isset($this->definitions()[$id]);
    }

    /**
     * @return array{id: string, name: string, category: string, description: string, components: list<string>, template: string}
     */
    public function get(string $id): array
    {
        $defs = $this->definitions();
        if (!isset($defs[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown Zokeibi block "%s". Available: %s.', $id, implode(', ', array_keys($defs))));
        }

        return ['id' => $id, ...$defs[$id]];
    }

    /**
     * Installs one or more turnkey SaaS page blocks into `views/blocks/` along with their required UI primitives.
     *
     * @param list<string> $blockIds
     */
    public function install(string $projectDir, array $blockIds, ComponentInstaller $installer, bool $force = false): InstallReport
    {
        $root = rtrim($projectDir, '\\/');
        $blocksDir = $root . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'blocks';
        if (!is_dir($blocksDir) && !mkdir($blocksDir, 0o775, true) && !is_dir($blocksDir)) {
            throw new RuntimeException('Unable to create views/blocks directory.');
        }

        $written = [];
        $skipped = [];
        /** @var array<string, true> $requiredComponents */
        $requiredComponents = [];

        foreach ($blockIds as $id) {
            $block = $this->get($id);
            foreach ($block['components'] as $comp) {
                $requiredComponents[$comp] = true;
            }

            $dest = $blocksDir . DIRECTORY_SEPARATOR . $id . '.kgn.html';
            $rel = 'views/blocks/' . $id . '.kgn.html';
            if (is_link($dest)) {
                throw new RuntimeException(sprintf('Refusing to overwrite symlinked block "%s".', $id));
            }
            if (is_file($dest) && !$force) {
                $skipped[] = $rel;
                continue;
            }
            $tmp = $dest . '.tmp.' . bin2hex(random_bytes(4));
            if (false === file_put_contents($tmp, $block['template']) || !rename($tmp, $dest)) {
                @unlink($tmp);
                throw new RuntimeException(sprintf('Unable to write Zokeibi block "%s".', $id));
            }
            $written[] = $rel;
        }

        if ([] !== $requiredComponents) {
            $compReport = $installer->add($root, array_keys($requiredComponents), false);
            $written = [...$written, ...$compReport->written];
            $skipped = [...$skipped, ...$compReport->skipped];
        }

        return new InstallReport('block:add', $root, $written, $skipped);
    }

    /**
     * @return array<string, array{name: string, category: string, description: string, components: list<string>, template: string}>
     */
    private function definitions(): array
    {
        return [
            'saas-shell' => [
                'name' => 'Responsive SaaS App Shell',
                'category' => 'layouts',
                'description' => 'Sidebar workspace layout with mobile slide-over drawer, Cmd+K command palette, user avatar, and Live 2.5 slot.',
                'components' => ['button', 'badge', 'avatar', 'drawer', 'command-palette', 'kbd'],
                'template' => <<<'HTML'
<div class="z-stack" @state(['navOpen' => false])>
  <header class="z-between">
    <div class="z-row">
      <x-ui.button variant="outline" size="sm" k-click="set(navOpen, true)" data-z-open="#mobile-nav">Menu</x-ui.button>
      <strong>{{ $appName ?? 'Workspace' }}</strong>
      <x-ui.badge variant="emerald" dot="true">Production</x-ui.badge>
    </div>
    <div class="z-row">
      <x-ui.command-palette id="shell-cmdk" trigger-label="Search Workspace" items="dashboard:Dashboard:/|settings:Settings:/settings|billing:Billing:/billing" />
      <x-ui.avatar :initials="$userInitials ?? 'ZK'" :name="$userName ?? 'Account'" status="online" />
    </div>
  </header>
  <x-ui.drawer id="mobile-nav" title="Navigation" show="navOpen">
    <nav aria-label="Workspace navigation" class="z-stack-sm">
      <a class="z-btn z-btn-ghost" href="/">Dashboard</a>
      <a class="z-btn z-btn-ghost" href="/settings">Settings</a>
      <a class="z-btn z-btn-ghost" href="/billing">Billing</a>
    </nav>
  </x-ui.drawer>
  <main id="workspace-main">
    @yield('content')
  </main>
</div>
HTML,
            ],
            'auth-login' => [
                'name' => 'Auth Sign-In & Passkey Screen',
                'category' => 'auth',
                'description' => 'Accessible sign-in card with CSRF hidden field, PRG old-input feedback, remember-me switch, and Passkey CTA.',
                'components' => ['card', 'input', 'switch', 'button', 'alert'],
                'template' => <<<'HTML'
<main class="z-stack">
  <x-ui.card title="Sign in to your workspace" badge="Secure">
    @if (isset($errorBanner) && $errorBanner !== '')
      <x-ui.alert variant="danger" title="Sign-in failed">{{ $errorBanner }}</x-ui.alert>
    @endif
    <form method="POST" action="/login" class="z-stack">
      <input type="hidden" name="_csrf" value="{{ $csrf ?? '' }}">
      <x-ui.input name="email" type="email" label="Work Email" :value="$old['email'] ?? ''" required="true" />
      <x-ui.input name="password" type="password" label="Password" required="true" />
      <x-ui.switch name="remember" label="Keep me signed in on this device" />
      <div class="z-row">
        <x-ui.button type="submit" variant="primary">Sign In</x-ui.button>
        <x-ui.button type="button" variant="outline" kgn-post="/auth/passkey/options" kgn-target="#passkey-status">Sign in with Passkey</x-ui.button>
      </div>
      <div id="passkey-status" aria-live="polite"></div>
    </form>
  </x-ui.card>
</main>
HTML,
            ],
            'settings-billing' => [
                'name' => 'Account & Billing Settings',
                'category' => 'saas',
                'description' => 'Workspace profile form, live subscription KPIs, region combobox, and danger-zone confirmation modal.',
                'components' => ['card', 'kpi', 'input', 'combobox', 'button', 'modal', 'badge'],
                'template' => <<<'HTML'
<section class="z-stack" @state(['deleteOpen' => false])>
  <div class="z-grid-3">
    <x-ui.kpi label="Current Plan" :value="$planName ?? 'Pro'" delta="Active" trend="up" variant="emerald" />
    <x-ui.kpi label="Seats Used" :value="$seatsUsed ?? '8 / 15'" delta="53%" />
    <x-ui.kpi label="Next Invoice" :value="$nextAmount ?? '$49.00'" delta="Monthly" />
  </div>
  <x-ui.card title="Workspace Profile" badge="Settings">
    <form method="POST" action="/settings/profile" class="z-stack" kgn-post="/settings/profile" kgn-target="#settings-notice" kgn-swap="morph">
      <input type="hidden" name="_csrf" value="{{ $csrf ?? '' }}">
      <x-ui.input name="workspace_name" label="Workspace Name" :value="$workspaceName ?? ''" required="true" />
      <x-ui.combobox name="region" label="Primary Data Region" options="us-east:US East|eu-central:EU Central|ap-northeast:Tokyo" :value="$region ?? 'us-east'" />
      <div class="z-between">
        <x-ui.button type="submit" variant="primary">Save Changes</x-ui.button>
        <x-ui.button type="button" variant="danger" k-click="set(deleteOpen, true)" data-z-open="#delete-workspace-modal">Delete Workspace</x-ui.button>
      </div>
    </form>
    <div id="settings-notice" aria-live="polite"></div>
  </x-ui.card>
  <x-ui.modal id="delete-workspace-modal" title="Delete Workspace" show="deleteOpen" variant="danger">
    <p>This action permanently removes workspace data and revokes API tokens.</p>
    <form method="POST" action="/settings/delete" class="z-row">
      <input type="hidden" name="_csrf" value="{{ $csrf ?? '' }}">
      <x-ui.button type="submit" variant="danger">Confirm Delete</x-ui.button>
      <x-ui.button type="button" variant="outline" k-click="set(deleteOpen, false)" data-z-close="#delete-workspace-modal">Cancel</x-ui.button>
    </form>
  </x-ui.modal>
</section>
HTML,
            ],
            'pricing-grid' => [
                'name' => '3-Tier SaaS Pricing Grid',
                'category' => 'marketing',
                'description' => 'Accessible 3-column Starter / Pro / Enterprise pricing layout themed automatically via OKLCH tokens.',
                'components' => ['card', 'badge', 'button'],
                'template' => <<<'HTML'
<section class="z-grid-3" aria-label="Pricing plans">
  <x-ui.card title="Starter" badge="Free">
    <p><strong>$0 / month</strong> — Everything needed to ship your first Kongono app.</p>
    <x-ui.button variant="outline" kgn-get="/signup?plan=starter">Get Started</x-ui.button>
  </x-ui.card>
  <x-ui.card title="Pro" badge="Popular">
    <p><strong>$29 / month</strong> — Multi-tenant isolation, priority queues, and team seats.</p>
    <x-ui.button variant="primary" kgn-get="/signup?plan=pro">Start 14-Day Trial</x-ui.button>
  </x-ui.card>
  <x-ui.card title="Enterprise" badge="Custom">
    <p><strong>Custom</strong> — Dedicated FrankenPHP clusters, SSO/SAML, and SLA support.</p>
    <x-ui.button variant="outline" kgn-get="/contact">Contact Sales</x-ui.button>
  </x-ui.card>
</section>
HTML,
            ],
            'empty-onboarding' => [
                'name' => 'Onboarding Stepper & Empty State',
                'category' => 'saas',
                'description' => 'Multi-step onboarding wizard paired with a calm empty-state call to action.',
                'components' => ['stepper', 'empty-state', 'button'],
                'template' => <<<'HTML'
<section class="z-stack">
  <x-ui.stepper steps="Create Account|Connect Database|Run kongo prove|Deploy" :current="$step ?? 2" />
  <x-ui.empty-state icon="造" title="No projects yet" description="Create your first project to unlock live telemetry, proofs, and zero-downtime deploys.">
    <x-ui.button variant="primary" kgn-get="/projects/new">Create Project</x-ui.button>
  </x-ui.empty-state>
</section>
HTML,
            ],
            'error-page' => [
                'name' => 'Zero-Leak Error Page (KGN Ref)',
                'category' => 'system',
                'description' => 'Calm, accessible 404/500 error card displaying only the safe KGN-* Reference ID without leaking internals.',
                'components' => ['card', 'badge', 'button', 'alert'],
                'template' => <<<'HTML'
<main class="z-stack">
  <x-ui.card :title="$title ?? 'Something went wrong'" :badge="$statusCode ?? '500'">
    <x-ui.alert variant="warning" title="Reference ID">{{ $reference ?? 'KGN-UNKNOWN' }}</x-ui.alert>
    <p>{{ $message ?? 'The request could not be completed. Share the reference ID above with support.' }}</p>
    <div class="z-row">
      <a class="z-btn z-btn-primary" href="/">Return Home</a>
    </div>
  </x-ui.card>
</main>
HTML,
            ],
            'analytics-dashboard' => [
                'name' => 'Executive Analytics Dashboard (0 B JS Charts)',
                'category' => 'saas',
                'description' => 'Full analytics screen with breadcrumb trail, date-range picker, KPI strip, pure-SVG Bar/Sparkline/Donut charts, and sortable/searchable DataTable.',
                'components' => ['breadcrumb', 'date-range', 'kpi', 'chart', 'table', 'badge', 'card'],
                'template' => <<<'HTML'
<section class="z-stack">
  <x-ui.breadcrumb items="Workspace:/|Analytics" />
  <div class="z-grid-3">
    <x-ui.kpi label="Monthly Revenue" :value="$mrr ?? '$48,920'" delta="+24.2%" trend="up" variant="emerald" />
    <x-ui.kpi label="Active Workspaces" :value="$tenants ?? '1,284'" delta="+9.1%" trend="up" variant="accent" />
    <x-ui.kpi label="P95 Latency" :value="$p95 ?? '11.4 ms'" delta="-31%" trend="up" />
  </div>
  <div class="z-grid-2">
    <x-ui.chart type="bar" title="Daily API Requests (k)" meta="Last 5 days" data="Mon:32|Tue:49|Wed:44|Thu:68|Fri:61" />
    <x-ui.chart type="donut" title="Plan Distribution" meta="Active seats" data="Starter:42|Pro:38|Enterprise:20" />
  </div>
  <div class="z-grid-2">
    <x-ui.date-range name="analytics_window" label="Reporting Window" start="2026-11-01" end="2026-11-24" presets="true" />
    <x-ui.chart type="sparkline" title="Conversion Velocity" meta="7d trend" data="D1:12|D2:19|D3:17|D4:26|D5:31|D6:29|D7:38" />
  </div>
  <x-ui.card title="Top Endpoints" badge="Live Sort & Filter" badge-variant="emerald">
    <x-ui.table caption="Endpoint performance breakdown" searchable="true" sortable="true" search-placeholder="Filter endpoints...">
      <thead>
        <tr>
          <th scope="col">Route</th>
          <th scope="col">Requests</th>
          <th scope="col">P95</th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr><td>/api/orders</td><td>42,190</td><td>8.4 ms</td><td><x-ui.badge variant="emerald">Healthy</x-ui.badge></td></tr>
        <tr><td>/api/search</td><td>18,412</td><td>14.2 ms</td><td><x-ui.badge variant="emerald">Healthy</x-ui.badge></td></tr>
        <tr><td>/api/exports</td><td>3,920</td><td>41.0 ms</td><td><x-ui.badge variant="amber">Warm</x-ui.badge></td></tr>
      </tbody>
    </x-ui.table>
  </x-ui.card>
</section>
HTML,
            ],
            'api-keys-audit' => [
                'name' => 'API Keys, 2FA Verification & Audit Trail',
                'category' => 'security',
                'description' => 'Developer credentials view pairing masked copy-to-clipboard PAT tokens, 6-digit OTP verification, and a zero-JS security timeline.',
                'components' => ['breadcrumb', 'card', 'copy-field', 'otp-input', 'timeline', 'button', 'badge'],
                'template' => <<<'HTML'
<section class="z-stack">
  <x-ui.breadcrumb items="Workspace:/|Security:/security|API Keys" />
  <div class="z-grid-2">
    <x-ui.card title="Personal Access Token" badge="SHA-256 Hashed" badge-variant="emerald">
      <div class="z-stack">
        <x-ui.copy-field label="Active Production Token" :value="$maskedToken ?? 'kgn_pat_example_replace_me'" masked="true" />
        <form method="POST" action="/security/keys/rotate" class="z-stack">
          <input type="hidden" name="_csrf" value="{{ $csrf ?? '' }}">
          <x-ui.otp-input name="totp_code" label="Confirm Rotation with 2FA Code" length="6" hint="Enter your 6-digit authenticator code." />
          <div class="z-row">
            <x-ui.button type="submit" variant="primary">Rotate API Key</x-ui.button>
          </div>
        </form>
      </div>
    </x-ui.card>
    <x-ui.card title="Security Audit Timeline" badge="Immutable">
      <x-ui.timeline items="API Key Verified|Just now|Read-only scope checked via CapabilityMiddleware|emerald;;2FA Challenge Passed|18m ago|WebAuthn passkey on macOS|blue;;Old Key Revoked|2d ago|kgn_pat_***81a invalidated|amber" />
    </x-ui.card>
  </div>
</section>
HTML,
            ],
        ];
    }
}
