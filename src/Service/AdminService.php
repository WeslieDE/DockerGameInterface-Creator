<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Service;

use tk\weslie\SgiCreator\Docker\DockerClient;
use tk\weslie\SgiCreator\Docker\DockerException;
use tk\weslie\SgiCreator\Http\HttpException;

/**
 * The management side of SGI-Creator: list every SGI-managed container and
 * delete one completely — its container, its per-server data volume and all of
 * its backups.
 *
 * Consistent with SGI's least-privilege model, the client never sends a
 * container id. A server is addressed by its sgi.token, which is resolved to
 * exactly one container id here, server-side, on every request.
 *
 * The data of an SGI-Creator server lives in two places, mirroring how SGI and
 * this tool provision it:
 *   - the per-server host bind root  <volumeRoot>/<token>   (the %%path%% mount)
 *   - the token's backup sub-folder  <backupVolume>:/backup/<token>
 * SGI-Creator runs inside a container and does not mount either of these into
 * itself. Deletion therefore runs a one-shot alpine helper (exactly like SGI's
 * backup helpers) that mounts both and removes the token's folders. This keeps
 * the tool socket-only and needs no extra mounts on the SGI-Creator container.
 */
final class AdminService
{
    /** Small, ubiquitous image used for the one-shot delete helper. */
    private const HELPER_IMAGE = 'alpine';

    public function __construct(
        private readonly DockerClient $docker,
        private readonly string $volumeRoot,    // host root for the %%path%% binds
        private readonly string $backupVolume,  // named backup volume (SGI: sgi_backup)
    ) {}

    /* ---------------------------------------------------------------- */
    /* Overview                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Every container carrying an sgi.token label. The token is intentionally
     * exposed here — this whole panel is password-gated admin surface, and the
     * point of the list is to recover a forgotten token.
     *
     * @return list<array<string,mixed>>
     */
    public function listServers(): array
    {
        // Label-existence filter (no value) → all SGI-managed containers,
        // running or stopped (all: true).
        $containers = $this->docker->listContainers(['label' => ['sgi.token']], all: true);

        $out = [];
        foreach ($containers as $c) {
            $labels = $c['Labels'] ?? [];
            $token  = (string) ($labels['sgi.token'] ?? '');
            if ($token === '') {
                continue;
            }
            $out[] = [
                'token'   => $token,
                'name'    => (string) ($labels['sgi.name'] ?? '') ?: ltrim((string) ($c['Names'][0] ?? ''), '/'),
                'image'   => (string) ($c['Image'] ?? ''),
                'state'   => (string) ($c['State'] ?? ''),   // running | exited | created | …
                'status'  => (string) ($c['Status'] ?? ''),  // human "Up 3 hours" / "Exited (0) …"
                'running' => ($c['State'] ?? '') === 'running',
                'ports'   => $this->configuredPorts((string) ($c['Id'] ?? '')),
            ];
        }
        // Stable, name-sorted output.
        usort($out, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Delete                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Remove a server completely: its container (force), its data volume folder
     * and its backups. Idempotent on the data side — a missing folder is fine.
     */
    public function deleteServer(string $token): void
    {
        $token = $this->safeToken($token);
        $id    = $this->resolveToken($token);

        // 1) The container first — this releases the bind mount so the host
        //    folder can then be removed cleanly.
        try {
            $this->docker->removeContainer($id, force: true);
        } catch (DockerException $e) {
            throw new HttpException(502, 'Docker could not remove the container: ' . $e->getMessage(), 'DELETE_FAILED');
        }

        // 2) Volume data and backups, wiped by a fire-and-forget helper. Files
        //    under both roots are typically root-owned (Docker created them), so
        //    www-data cannot unlink them directly — the helper runs as root.
        //    The rm of large volumes/backups can be slow, so we do NOT wait for
        //    it: the request returns as soon as the helper is started.
        $this->purgeData($token);
    }

    /**
     * Wipe <volumeRoot>/<token> and <backupVolume>:/backup/<token> using a single
     * alpine helper that mounts both. The token is validated to a safe charset
     * (safeToken) and additionally single-quoted, so it cannot break out of the
     * rm command.
     *
     * Fire-and-forget: the helper is started detached with AutoRemove, so Docker
     * runs it to completion and discards it on its own. The triggering HTTP
     * request does not block on the (potentially large) deletion — only on
     * starting the helper, which is near-instant. The server has already been
     * removed from Docker at this point, so it disappears from the list at once
     * even while its data is still being cleaned up in the background.
     */
    private function purgeData(string $token): void
    {
        $root = rtrim($this->volumeRoot, '/');
        if ($root === '') {
            $root = '/home/GameServerVolumes';
        }

        try {
            $this->docker->ensureImage(self::HELPER_IMAGE);

            $q      = "'" . str_replace("'", "'\\''", $token) . "'";
            $script = 'rm -rf /vroot/' . $q . ' /backup/' . $q;

            $spec = [
                'Image'      => self::HELPER_IMAGE,
                'Cmd'        => ['sh', '-c', $script],
                'HostConfig' => [
                    // Detached + self-cleaning: Docker removes the helper once it
                    // exits, so no follow-up request is needed and nothing waits.
                    'AutoRemove' => true,
                    'Binds'      => [
                        $root . ':/vroot',                 // host bind root (data volumes)
                        $this->backupVolume . ':/backup',  // named backup volume
                    ],
                ],
            ];

            // Create + start only — we return without waiting for the rm to end.
            $helperId = $this->docker->createContainer($spec);
            $this->docker->start($helperId);
        } catch (DockerException $e) {
            // The container is already gone; surface the fact that the background
            // cleanup could not even be started so the data may need manual
            // removal. (A failure *during* the async rm is not observable here.)
            throw new HttpException(
                502,
                'The container was removed, but its data cleanup could not be started: ' . $e->getMessage(),
                'CLEANUP_FAILED'
            );
        }
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                          */
    /* ---------------------------------------------------------------- */

    /** Resolve a token to exactly one container id (404 none, 409 ambiguous). */
    private function resolveToken(string $token): string
    {
        $containers = $this->docker->listContainers(['label' => ['sgi.token=' . $token]], all: true);
        if (count($containers) === 0) {
            throw new HttpException(404, 'No server exists for that token.', 'NO_SERVER');
        }
        if (count($containers) > 1) {
            throw new HttpException(409, 'That token maps to more than one container.', 'AMBIGUOUS_TOKEN');
        }
        return (string) $containers[0]['Id'];
    }

    /** Token is used as a folder name — restrict to a safe charset. */
    private function safeToken(string $token): string
    {
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $token)) {
            throw new HttpException(400, 'The token contains characters that are not valid.', 'BAD_TOKEN');
        }
        return $token;
    }

    /**
     * The container's configured host-port mappings, read from
     * HostConfig.PortBindings via inspect. Unlike the container-list "Ports"
     * field, these are present even when the container is stopped — so a stopped
     * server still shows the port it will publish once started.
     *
     * @return list<array<string,mixed>>
     */
    private function configuredPorts(string $id): array
    {
        if ($id === '') {
            return [];
        }
        try {
            $inspect = $this->docker->inspect($id);
        } catch (DockerException) {
            return []; // never let one unreadable container break the whole list
        }

        $bindings = $inspect['HostConfig']['PortBindings'] ?? [];
        if (!is_array($bindings)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($bindings as $portProto => $binds) {
            // Key looks like "25565/tcp"; split into container port + protocol.
            [$cPort, $proto] = array_pad(explode('/', (string) $portProto, 2), 2, 'tcp');
            foreach ((array) $binds as $b) {
                $host = (int) ($b['HostPort'] ?? 0);
                if ($host <= 0 || isset($seen[$host])) {
                    continue; // skip auto-assigned/duplicate (tcp+udp) host ports
                }
                $seen[$host] = true;
                $out[] = [
                    'host'      => $host,
                    'container' => (int) $cPort,
                    'proto'     => $proto,
                ];
            }
        }
        // Stable ascending order by host port.
        usort($out, static fn($a, $b) => $a['host'] <=> $b['host']);
        return $out;
    }
}
