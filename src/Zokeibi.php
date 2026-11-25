<?php

declare(strict_types=1);

namespace Zokeibi;

use Zokeibi\Audit\UiAuditor;
use Zokeibi\Blocks\BlockCatalog;
use Zokeibi\Compiler\CssTreeShaker;
use Zokeibi\Generator\DtoUiGenerator;
use Zokeibi\Installer\ComponentInstaller;
use Zokeibi\Proof\UiProver;
use Zokeibi\Registry\ComponentRegistry;
use Zokeibi\Studio\StudioStore;
use Zokeibi\Studio\TokenExporter;
use Zokeibi\Watch\CssWatcher;

/**
 * Zokeibi (造形美 — "Beauty of Form") main runtime facade.
 * Zero runtime Composer dependencies, zero static mutable state; safe for PHP-FPM, FrankenPHP, RoadRunner, and CLI.
 */
final readonly class Zokeibi
{
    public ComponentRegistry $registry;
    public CssTreeShaker $compiler;
    public UiAuditor $auditor;
    public ComponentInstaller $installer;
    public StudioStore $studio;
    public TokenExporter $exporter;
    public BlockCatalog $blocks;
    public DtoUiGenerator $generator;
    public UiProver $prover;
    public CssWatcher $watcher;

    public function __construct(?string $resourcesDir = null)
    {
        $this->registry = new ComponentRegistry($resourcesDir);
        $this->compiler = new CssTreeShaker($this->registry);
        $this->auditor = new UiAuditor($this->registry);
        $this->installer = new ComponentInstaller($this->registry);
        $this->studio = new StudioStore();
        $this->exporter = new TokenExporter();
        $this->blocks = new BlockCatalog();
        $this->generator = new DtoUiGenerator();
        $this->prover = new UiProver($this->auditor, $this->compiler, $this->studio);
        $this->watcher = new CssWatcher($this->compiler, $this->studio);
    }

    public function version(): string
    {
        return $this->registry->version();
    }
}
