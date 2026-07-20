<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Service;

use tk\weslie\SgiCreator\Compose\ComposeParser;
use tk\weslie\SgiCreator\Docker\DockerClient;
use tk\weslie\SgiCreator\Docker\DockerException;
use tk\weslie\SgiCreator\Http\HttpException;
use tk\weslie\SgiCreator\Template\Template;

/**
 * Turns a template into a running container.
 *
 * The template author owns the whole compose file; SGI-Creator only fills in the
 * three placeholders and hands the result to Docker:
 *
 *   %%sgi.token%%  → a fresh, unique server token in the form ######-####-####
 *   %%path%%       → the per-server host bind root  <SGIC_VOLUME_ROOT>/<token>
 *   %%port%%       → a free host port (each occurrence gets its own free port)
 *
 * Everything else — labels, volumes, restart policy, stdin — is exactly what the
 * template declares. The one convenience we add: if the template forgot to label
 * the container with sgi.token, we inject it, so the token we hand back always
 * actually logs into SGI.
 *
 * Fully stateless: the token's uniqueness and free ports are checked against the
 * live Docker runtime, never a database.
 */
final class CreatorService
{
    /** Token charset — unambiguous uppercase (no 0/O/1/I) so it is easy to read/type. */
    private const TOKEN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Token group lengths: ######-####-#### (6-4-4). */
    private const TOKEN_GROUPS = [6, 4, 4];

    public function __construct(
        private readonly DockerClient $docker,
        private readonly ComposeParser $parser,
        private readonly string $volumeRoot,   // host root for %%path%% binds
        private readonly string $host,         // hostname shown in the "address"
        private readonly string $sgiUrl = '',  // optional SGI panel URL (deep link)
        private readonly int $portMin = 20000, // free-port scan window (inclusive)
        private readonly int $portMax = 40000,
    ) {}

    /**
     * Create AND start a container from a template. Returns the confirmation the
     * frontend renders. Only returns once the container is actually running.
     *
     * @return array{templateName:string,name:string,address:string,token:string,sgiUrl?:string}
     */
    public function create(Template $tpl): array
    {
        $token = $this->generateToken();
        $path  = rtrim($this->volumeRoot, '/') . '/' . $token;
        $nPort = substr_count($tpl->raw, '%%port%%');

        $blocked = $this->usedHostPorts();
        $ensured = false;
        $lastError = null;

        // Bounded retry: our pre-scan only knows Docker's own bindings, so a
        // non-Docker listener on the host can still make the start fail. On such
        // a conflict we block the just-tried ports and reallocate upward.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $ports = $this->allocatePorts($nPort, $blocked);
            $yaml  = $this->substitute($tpl->raw, $token, $path, $ports);
            $svc   = $this->parser->parse($yaml);

            if (!$ensured) {
                $this->docker->ensureImage($svc['image']);
                $ensured = true;
            }

            $name = $this->containerName($svc, $token);
            $spec = $this->buildSpec($svc, $token);

            try {
                $id = $this->docker->createContainer($spec, $name);
            } catch (DockerException $e) {
                // A name clash won't improve by bumping ports — surface it.
                if ($this->isNameConflict($e->getMessage())) {
                    throw new HttpException(409, 'A container with this name already exists.', 'NAME_TAKEN');
                }
                throw $this->dockerToHttp($e);
            }

            try {
                $this->docker->start($id);
            } catch (DockerException $e) {
                // Roll back the created-but-not-started container.
                $this->docker->removeContainer($id, force: true);
                $lastError = $e;
                if ($nPort === 0 || !$this->isPortConflict($e->getMessage())) {
                    throw $this->dockerToHttp($e);
                }
                foreach ($ports as $p) {
                    $blocked[$p] = true;
                }
                continue;
            }

            return $this->confirmation($tpl, $svc, $token, $ports);
        }

        throw new HttpException(
            409,
            'No free host port could be assigned for the container.'
                . ($lastError ? ' Last error: ' . $lastError->getMessage() : ''),
            'NO_FREE_PORT'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Placeholder substitution                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Replace the three placeholders. Token and path are single values applied
     * everywhere; each %%port%% occurrence is consumed in order from $ports.
     *
     * @param list<int> $ports
     */
    private function substitute(string $raw, string $token, string $path, array $ports): string
    {
        $out = str_replace(['%%sgi.token%%', '%%path%%'], [$token, $path], $raw);

        $i = 0;
        return preg_replace_callback('/%%port%%/', static function () use (&$i, $ports): string {
            return (string) ($ports[$i++] ?? 0);
        }, $out) ?? $out;
    }

    /* ---------------------------------------------------------------- */
    /* Spec building                                                    */
    /* ---------------------------------------------------------------- */

    /**
     * Faithfully translate the parsed compose service into a Docker create spec.
     * The template is authoritative; we only guarantee the sgi.token label.
     *
     * @param array<string,mixed> $svc
     * @return array<string,mixed>
     */
    private function buildSpec(array $svc, string $token): array
    {
        $labels = $svc['labels'];
        // Guarantee the returned token actually addresses this container in SGI.
        if (!isset($labels['sgi.token']) || $labels['sgi.token'] === '') {
            $labels['sgi.token'] = $token;
        }

        $binds   = [];
        $volumes = [];   // anonymous volumes (no source)
        foreach ($svc['volumes'] as $vol) {
            if ($vol['source'] === '') {
                $volumes[$vol['target']] = new \stdClass();
                continue;
            }
            $binds[] = $vol['source'] . ':' . $vol['target'] . ($vol['readonly'] ? ':ro' : '');
        }

        $exposed      = [];
        $portBindings = [];
        foreach ($svc['ports'] as $p) {
            $key                = $p['container'] . '/' . $p['proto'];
            $exposed[$key]      = new \stdClass();
            $portBindings[$key][] = ['HostPort' => (string) $p['host']];
        }

        $hostConfig = [];
        if ($binds !== []) {
            $hostConfig['Binds'] = $binds;
        }
        if ($portBindings !== []) {
            $hostConfig['PortBindings'] = $portBindings;
        }
        $restart = $this->restartPolicy($svc['restart']);
        if ($restart !== null) {
            $hostConfig['RestartPolicy'] = ['Name' => $restart];
        }

        $spec = [
            'Image'  => $svc['image'],
            // The SGI console needs an open stdin and no TTY (docker attach).
            // Default it on for these SGI templates unless the compose opts out
            // (stdin_open: false); null means "unspecified" → default true.
            'OpenStdin' => $svc['openStdin'] ?? true,
            'Tty'       => $svc['tty'],
            'Labels'    => $labels,
        ];
        if ($svc['env'] !== []) {
            $spec['Env'] = $svc['env'];
        }
        if ($svc['command'] !== null) {
            $spec['Cmd'] = $svc['command'];
        }
        if ($exposed !== []) {
            $spec['ExposedPorts'] = $exposed;
        }
        if ($volumes !== []) {
            $spec['Volumes'] = $volumes;
        }
        if ($hostConfig !== []) {
            $spec['HostConfig'] = $hostConfig;
        }
        return $spec;
    }

    /** Map a compose restart value to a Docker RestartPolicy name (or null). */
    private function restartPolicy(?string $restart): ?string
    {
        return match ($restart) {
            'always'         => 'always',
            'unless-stopped' => 'unless-stopped',
            'on-failure'     => 'on-failure',
            default          => null,
        };
    }

    /* ---------------------------------------------------------------- */
    /* Confirmation                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $svc
     * @param list<int> $ports
     * @return array{templateName:string,name:string,address:string,token:string,sgiUrl?:string}
     */
    private function confirmation(Template $tpl, array $svc, string $token, array $ports): array
    {
        // The address the player connects to = the first published host port.
        $publicPort = $svc['ports'][0]['host'] ?? ($ports[0] ?? 0);
        $address    = $this->host . ($publicPort > 0 ? ':' . $publicPort : '');

        $result = [
            'templateName' => $tpl->name,
            'name'         => $this->containerName($svc, $token),
            'address'      => $address,
            'token'        => $token,
        ];
        if ($this->sgiUrl !== '') {
            $result['sgiUrl'] = $this->sgiUrl;
        }
        return $result;
    }

    /* ---------------------------------------------------------------- */
    /* Token / naming                                                   */
    /* ---------------------------------------------------------------- */

    /** A fresh token "XXXXXX-XXXX-XXXX" not already used by any container. */
    private function generateToken(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $token = implode('-', array_map([$this, 'randomGroup'], self::TOKEN_GROUPS));
            if ($this->docker->listContainers(['label' => ['sgi.token=' . $token]], all: true) === []) {
                return $token;
            }
        }
        throw new HttpException(500, 'Could not generate a unique server token.', 'TOKEN_FAIL');
    }

    private function randomGroup(int $len): string
    {
        $alpha = self::TOKEN_ALPHABET;
        $max   = strlen($alpha) - 1;
        $s     = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $alpha[random_int(0, $max)];
        }
        return $s;
    }

    /**
     * A valid, likely-unique container name from the service/container name plus
     * a token fragment (Docker names must be unique and match a strict pattern).
     *
     * @param array<string,mixed> $svc
     */
    private function containerName(array $svc, string $token): string
    {
        $base = (string) ($svc['containerName'] ?? '') ?: (string) $svc['service'];
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $base) ?? '');
        $base = trim($base, '-_.');
        if (strlen($base) < 2) {
            $base = 'sgi-server';
        }
        $suffix = strtolower(explode('-', $token)[0]);
        return $base . '-' . $suffix;
    }

    /* ---------------------------------------------------------------- */
    /* Ports                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Pick $count distinct free host ports, scanning upward from portMin and
     * skipping anything in $blocked (Docker's bindings + ports we already tried).
     *
     * @param array<int,bool> $blocked
     * @return list<int>
     */
    private function allocatePorts(int $count, array $blocked): array
    {
        $out  = [];
        $port = $this->portMin;
        while (count($out) < $count) {
            while ($port <= $this->portMax && isset($blocked[$port])) {
                $port++;
            }
            if ($port > $this->portMax) {
                throw new HttpException(409, 'No free host port is available.', 'NO_FREE_PORT');
            }
            $blocked[$port] = true;   // don't hand the same port out twice
            $out[]          = $port;
            $port++;
        }
        return $out;
    }

    /**
     * Host ports that must be treated as taken — NOT just the ones live right
     * now. A stopped container still owns its configured host port: it can be
     * started at any moment and would then collide, so its port must never be
     * handed to a new server. We therefore scan ALL containers (all: true):
     *
     *   - running containers → the live bindings from the list response
     *     (`Ports[].PublicPort`), which also covers dynamically-assigned ports;
     *   - stopped/created containers → the *configured* host ports from
     *     `HostConfig.PortBindings` (via inspect), which the list omits because
     *     nothing is actually bound while the container is down.
     *
     * @return array<int,bool> set keyed by port number
     */
    private function usedHostPorts(): array
    {
        $used = [];
        foreach ($this->docker->listContainers([], all: true) as $c) {
            if (($c['State'] ?? '') === 'running') {
                foreach ($c['Ports'] ?? [] as $port) {
                    $public = (int) ($port['PublicPort'] ?? 0);
                    if ($public > 0) {
                        $used[$public] = true;
                    }
                }
                continue;
            }

            // Stopped/created: reserve the ports it is configured to publish.
            $id = (string) ($c['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $inspect = $this->docker->inspect($id);
            } catch (DockerException) {
                continue; // never let one unreadable container abort creation
            }
            $bindings = $inspect['HostConfig']['PortBindings'] ?? [];
            if (!is_array($bindings)) {
                continue;
            }
            foreach ($bindings as $binds) {
                foreach ((array) $binds as $b) {
                    $host = (int) ($b['HostPort'] ?? 0);
                    if ($host > 0) {
                        $used[$host] = true;
                    }
                }
            }
        }
        return $used;
    }

    /* ---------------------------------------------------------------- */
    /* Error mapping                                                    */
    /* ---------------------------------------------------------------- */

    private function isPortConflict(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'port is already allocated')
            || str_contains($m, 'address already in use')
            || str_contains($m, 'bind for')
            || str_contains($m, 'failed to bind');
    }

    private function isNameConflict(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'name') && str_contains($m, 'already in use');
    }

    /** Map a Docker failure onto a user-facing HTTP error. */
    private function dockerToHttp(DockerException $e): HttpException
    {
        // 404 from Docker on create usually means the image is unavailable.
        if ($e->dockerStatus === 404) {
            return new HttpException(422, 'The template\'s image could not be found or pulled.', 'IMAGE_MISSING');
        }
        return new HttpException(502, 'Docker rejected the container: ' . $e->getMessage(), 'DOCKER_ERROR');
    }
}
