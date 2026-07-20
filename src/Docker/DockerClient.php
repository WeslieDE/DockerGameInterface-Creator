<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Docker;

/**
 * Thin client for the Docker Engine API over the Unix socket.
 *
 * All calls go through cURL with CURLOPT_UNIX_SOCKET_PATH; no TCP, no external
 * dependencies. Only the primitives SGI-Creator needs are exposed: list/inspect
 * (for free-port detection and name clashes), image pull, container create/start
 * and a best-effort remove for rollback.
 *
 * Deliberately mirrors SGI's own DockerClient so both tools behave identically
 * against the same daemon.
 */
final class DockerClient
{
    /**
     * Desired API version — broadly supported by Docker 20.10+. We never send a
     * version higher than what the daemon reports (see apiVersion()), so an older
     * engine does not reject us with HTTP 400 "client version is too new".
     */
    private const DEFAULT_API_VERSION = '1.41';

    /** Negotiated version, resolved lazily on first use and cached. */
    private ?string $apiVersion = null;

    public function __construct(
        private readonly string $socket = '/var/run/docker.sock',
    ) {}

    /* ---------------------------------------------------------------- */
    /* Queries                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * List containers. $filters is an associative array matching the Docker
     * filter schema, e.g. ['label' => ['sgi.token=abc']].
     *
     * @return array<int,array<string,mixed>>
     */
    public function listContainers(array $filters = [], bool $all = true): array
    {
        $query = ['all' => $all ? '1' : '0'];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }
        [$status, , $body] = $this->request('GET', '/containers/json?' . http_build_query($query));
        if ($status !== 200) {
            throw new DockerException("listContainers failed (HTTP $status)" . self::errBody($body), $status);
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Inspect a single container. Needed to read the *configured* port bindings
     * (HostConfig.PortBindings), which — unlike the container list — are present
     * even while the container is stopped.
     *
     * @return array<string,mixed>
     */
    public function inspect(string $id): array
    {
        [$status, , $body] = $this->request('GET', '/containers/' . rawurlencode($id) . '/json');
        if ($status !== 200) {
            throw new DockerException("inspect failed (HTTP $status)" . self::errBody($body), $status);
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /* ---------------------------------------------------------------- */
    /* Images                                                           */
    /* ---------------------------------------------------------------- */

    /** Ensure an image is present locally; pull it otherwise. */
    public function ensureImage(string $image): void
    {
        [$status] = $this->request('GET', '/images/' . rawurlencode($image) . '/json');
        if ($status === 200) {
            return;
        }
        // Split an optional "name:tag" (e.g. "itzg/minecraft-server:latest");
        // a ':' followed by a '/' is a registry port, not a tag.
        $name = $image;
        $tag  = 'latest';
        $pos  = strrpos($image, ':');
        if ($pos !== false && strpos($image, '/', $pos) === false) {
            $name = substr($image, 0, $pos);
            $tag  = substr($image, $pos + 1);
        }
        // Pull. The body is a stream of JSON progress lines we simply drain.
        [$pullStatus, , $pullBody] = $this->request(
            'POST',
            '/images/create?' . http_build_query(['fromImage' => $name, 'tag' => $tag])
        );
        if ($pullStatus !== 200) {
            throw new DockerException("could not pull image '$image' (HTTP $pullStatus)" . self::errBody($pullBody), $pullStatus);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Container lifecycle                                              */
    /* ---------------------------------------------------------------- */

    /**
     * Create a container from a spec, optionally under a chosen name.
     *
     * @param array<string,mixed> $spec
     * @return string new container id
     */
    public function createContainer(array $spec, ?string $name = null): string
    {
        $path = '/containers/create';
        if ($name !== null && $name !== '') {
            $path .= '?' . http_build_query(['name' => $name]);
        }
        [$status, , $body] = $this->request('POST', $path, $spec);
        if ($status !== 201) {
            throw new DockerException("create failed (HTTP $status)" . self::errBody($body), $status);
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return (string) $data['Id'];
    }

    /** Start a container. 204 = started, 304 = already running — both are success. */
    public function start(string $id): void
    {
        [$status, , $body] = $this->request('POST', '/containers/' . rawurlencode($id) . '/start');
        if ($status !== 204 && $status !== 304) {
            throw new DockerException("start failed (HTTP $status)" . self::errBody($body), $status);
        }
    }

    /** Remove a container (best effort — used to roll back a failed start). */
    public function removeContainer(string $id, bool $force = true): void
    {
        $this->request(
            'DELETE',
            '/containers/' . rawurlencode($id) . '?' . http_build_query(['force' => $force ? '1' : '0'])
        );
    }

    /* ---------------------------------------------------------------- */
    /* Transport                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Resolve the API version to talk to the daemon. Newer engines reject a
     * version that is too old *or* too new with HTTP 400, so we adopt the version
     * the daemon itself reports. Falls back to DEFAULT_API_VERSION on probe fail.
     */
    private function apiVersion(): string
    {
        if ($this->apiVersion !== null) {
            return $this->apiVersion;
        }
        $version = self::DEFAULT_API_VERSION;
        // Version-less request: always routes to the daemon's current API.
        [$status, , $body] = $this->send('GET', '/version', null);
        if ($status === 200) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['ApiVersion']) && is_string($data['ApiVersion']) && $data['ApiVersion'] !== '') {
                $version = $data['ApiVersion'];
            }
        }
        return $this->apiVersion = $version;
    }

    /** Turn a non-2xx Docker JSON body ({"message":"..."}) into a readable suffix. */
    private static function errBody(string $body): string
    {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return ': ' . $data['message'];
        }
        $body = trim($body);
        return $body === '' ? '' : ': ' . substr($body, 0, 300);
    }

    /**
     * @param array<string,mixed>|null $json body to send as JSON
     * @return array{0:int,1:string,2:string} [statusCode, headers, body]
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        return $this->send($method, '/v' . $this->apiVersion() . $path, $json);
    }

    /**
     * Raw transport. $absPath is used verbatim after the host, e.g.
     * "/v1.41/containers/json" or "/version" (un-versioned).
     *
     * @param array<string,mixed>|null $json body to send as JSON
     * @return array{0:int,1:string,2:string} [statusCode, headers, body]
     */
    private function send(string $method, string $absPath, ?array $json = null): array
    {
        $ch  = curl_init();
        $url = 'http://localhost' . $absPath;

        $headers = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_URL              => $url,
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_HEADER           => true,
            CURLOPT_CONNECTTIMEOUT   => 5,
            // Image pulls can be slow — give the whole create flow generous room.
            CURLOPT_TIMEOUT          => 300,
        ]);

        if ($json !== null) {
            $payload   = json_encode($json, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DockerException("docker socket error: $err", 0);
        }
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
    }
}
