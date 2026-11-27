<?php

declare(strict_types=1);

use Zokeibi\Zokeibi;
use Zokeibi\Playground\PlaygroundController;
use Zokeibi\Playground\StudioController;
use Kongono\Core\Cache\FileCache;
use Kongono\Core\Config;
use Kongono\Core\EnvLoader;
use Kongono\Core\Health\HealthCheck;
use Kongono\Core\Log\Logger;
use Kongono\Http\App;
use Kongono\Http\Live\LiveMiddleware;
use Kongono\Http\Middleware\CompressionMiddleware;
use Kongono\Http\Middleware\StaticFilesMiddleware;
use Kongono\Http\Session\CacheSessionStore;
use Kongono\Http\Session\SessionMiddleware;
use Kongono\Security\Middleware\AiThreatShieldMiddleware;
use Kongono\Security\Middleware\CsrfMiddleware;
use Kongono\Security\Middleware\RateLimitMiddleware;
use Kongono\Security\Middleware\SecurityHeadersMiddleware;
use Kongono\Security\TokenSigner;
use Kongono\View\ViewEngine;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

$playgroundDir = dirname(__DIR__);
$packageDir = dirname($playgroundDir);
EnvLoader::load($playgroundDir . DIRECTORY_SEPARATOR . '.env');

$studioProjectEnv = Config::env('ZOKEIBI_STUDIO_PROJECT');
$studioProjectDir = (null !== $studioProjectEnv && '' !== trim($studioProjectEnv) && is_dir(trim($studioProjectEnv)))
    ? trim($studioProjectEnv)
    : $playgroundDir;

$appEnv = strtolower(trim(Config::env('APP_ENV') ?? 'local'));
$isProduction = 'production' === $appEnv || 'prod' === $appEnv;
$debug = !$isProduction && filter_var(Config::env('APP_DEBUG') ?? 'false', FILTER_VALIDATE_BOOL);

$envKey = Config::env('APP_KEY');
$validEnvKey = null !== $envKey && strlen($envKey) >= 32 && !str_contains(strtolower($envKey), 'change-in-prod');
if ($isProduction && !$validEnvKey) {
    throw new RuntimeException('APP_KEY must be configured with at least 32 non-placeholder characters in production.');
}
$appKey = $validEnvKey
    ? $envKey
    : 'zokeibi-studio-local-signing-key-change-in-prod-v1';

$varDir = $playgroundDir . DIRECTORY_SEPARATOR . 'var';
$cacheDir = $varDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'app';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0o775, true) && !is_dir($cacheDir)) {
    throw new RuntimeException('Unable to create Zokeibi Playground cache directory.');
}

$cache = new FileCache($cacheDir);
$logger = new Logger('zokeibi-playground', retainInMemory: $debug);
$signer = new TokenSigner($appKey);
$zokeibi = new Zokeibi($packageDir . DIRECTORY_SEPARATOR . 'resources');

$health = (new HealthCheck())
    ->check('registry', static fn (): bool => 62 === $zokeibi->registry->count())
    ->check(
        'compiled_css',
        static fn (): bool => is_file($playgroundDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zokeibi.min.css'),
    );

$app = App::create($playgroundDir, name: 'Zokeibi Playground')
    ->debug($debug)
    ->config(['app' => ['name' => 'Zokeibi Playground', 'debug' => $debug]])
    ->views(autoReload: true)
    ->instance(LoggerInterface::class, $logger)
    ->instance(Zokeibi::class, $zokeibi)
    ->instance(HealthCheck::class, $health)
    ->instance(TokenSigner::class, $signer);

$app->container()->singleton(
    PlaygroundController::class,
    static function () use ($app, $zokeibi, $health, $playgroundDir, $studioProjectDir): PlaygroundController {
        $views = $app->container()->get(ViewEngine::class);
        assert($views instanceof ViewEngine);

        return new PlaygroundController($views, $zokeibi, $health, $playgroundDir, $studioProjectDir);
    },
);

$app->container()->singleton(
    StudioController::class,
    static function () use ($app, $zokeibi, $signer, $studioProjectDir): StudioController {
        $views = $app->container()->get(ViewEngine::class);
        assert($views instanceof ViewEngine);

        return new StudioController($views, $zokeibi, $signer, $studioProjectDir);
    },
);

$app->controller(PlaygroundController::class);
$app->controller(StudioController::class);

// Strict CSP: zero inline scripts AND zero inline styles!
$csp = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'";

$app->middleware(
    new CompressionMiddleware(),
    new SecurityHeadersMiddleware(
        contentSecurityPolicy: $csp,
        hstsMaxAgeSeconds: $isProduction ? 31536000 : 0,
    ),
    new AiThreatShieldMiddleware(
        cache: $cache,
        banSeconds: 900,
        maxProbesPerWindow: 40,
        clientResolver: static function (ServerRequestInterface $req): string {
            $ip = $req->getServerParams()['REMOTE_ADDR'] ?? null;

            return is_string($ip) && '' !== trim($ip) ? trim($ip) : 'inproc:' . bin2hex(random_bytes(4));
        },
    ),
    new LiveMiddleware(),
    new StaticFilesMiddleware($playgroundDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets', '/assets/'),
    new RateLimitMiddleware(cache: $cache, maxRequests: 300, windowSeconds: 60),
    new SessionMiddleware(new CacheSessionStore($cache), cookieName: 'zokeibi_studio_session', secure: $isProduction),
    new CsrfMiddleware(signer: $signer, sessionCookieName: 'zokeibi_studio_session', exemptPrefixes: ['/api/', '/_kongono']),
);

return $app;
