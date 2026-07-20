<?php
declare(strict_types=1);

/**
 * SGI-Creator API front-controller.
 *
 * Wires the (dependency-free) object graph from environment variables and hands
 * off to the Router. A plain PSR-4 autoloader is registered inline so the app
 * runs without a `composer install` step — there are no external dependencies.
 *
 * Configuration (all via -e environment variables, like SGI):
 *   SGIC_PASSWORD      required — the panel login password.
 *   SGIC_VOLUME_ROOT   host root for %%path%% binds     (default /home/GameServerVolumes)
 *   SGIC_HOST          hostname shown in the "address"   (default: request Host)
 *   SGIC_SGI_URL       optional SGI panel URL (deep link in the success dialog)
 *   SGIC_TEMPLATE_DIR  templates folder                  (default <app>/templates)
 *   SGIC_PORT_MIN      free-port scan window start       (default 20000)
 *   SGIC_PORT_MAX      free-port scan window end         (default 40000)
 *   DOCKER_SOCKET      Docker Unix socket                (default /var/run/docker.sock)
 */

use tk\weslie\SgiCreator\Auth\PasswordAuth;
use tk\weslie\SgiCreator\Compose\ComposeParser;
use tk\weslie\SgiCreator\Docker\DockerClient;
use tk\weslie\SgiCreator\Http\Router;
use tk\weslie\SgiCreator\Service\AdminService;
use tk\weslie\SgiCreator\Service\CreatorService;
use tk\weslie\SgiCreator\Template\TemplateRepository;

$root   = dirname(__DIR__, 2);
$srcDir = $root . '/src';

// Prefer Composer's autoloader if present, else register a minimal PSR-4 one.
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class) use ($srcDir): void {
        $prefix = 'tk\\weslie\\SgiCreator\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $srcDir . '/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/* ---- Configuration from the environment ---- */
$env = static fn(string $key, string $default = ''): string => (($v = getenv($key)) !== false && $v !== '') ? $v : $default;

$socket       = $env('DOCKER_SOCKET', '/var/run/docker.sock');
$password     = $env('SGIC_PASSWORD');
$volumeRoot   = $env('SGIC_VOLUME_ROOT', '/home/GameServerVolumes');
$templateDir  = $env('SGIC_TEMPLATE_DIR', $root . '/templates');
$sgiUrl       = $env('SGIC_SGI_URL');
$portMin      = (int) $env('SGIC_PORT_MIN', '20000');
$portMax      = (int) $env('SGIC_PORT_MAX', '40000');
// Named backup volume shared with SGI (per-token sub-folder /backup/<token>).
// A delete wipes this token's folder together with its data volume.
$backupVolume = $env('SGIC_BACKUP_VOLUME', 'sgi_backup');

// The address shown to the player. Prefer the configured host; otherwise fall
// back to the hostname the request came in on (stripped of the panel's port).
$host = $env('SGIC_HOST');
if ($host === '') {
    $rawHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host    = preg_replace('/:\d+$/', '', $rawHost) ?: 'localhost';
}

/* ---- Object graph ---- */
$docker  = new DockerClient($socket);
$auth    = new PasswordAuth($password);
$repo    = new TemplateRepository($templateDir);
$creator = new CreatorService(
    docker:     $docker,
    parser:     new ComposeParser(),
    volumeRoot: $volumeRoot,
    host:       $host,
    sgiUrl:     $sgiUrl,
    portMin:    $portMin > 0 ? $portMin : 20000,
    portMax:    $portMax > $portMin ? $portMax : 40000,
);
$admin   = new AdminService(
    docker:       $docker,
    volumeRoot:   $volumeRoot,
    backupVolume: $backupVolume,
);

(new Router($auth, $repo, $creator, $admin))->dispatch();
