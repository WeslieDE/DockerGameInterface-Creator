<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Compose;

use tk\weslie\SgiCreator\Http\HttpException;

/**
 * Parses a docker-compose YAML (with all %%...%% placeholders ALREADY substituted)
 * into a normalised, provider-neutral service description.
 *
 * Unlike SGI's own parser, this one KEEPS the volume source: SGI-Creator lets the
 * template author own the whole compose file, and the %%path%% placeholder has
 * already been resolved to a real host bind path by the time we parse. Pure
 * parsing only — no Docker calls, no placeholder handling, no port allocation.
 *
 * Hard rule: the file must define EXACTLY ONE service (one template = one
 * container). Zero or multiple services is a rejected template.
 *
 * Supported per-service keys: image (required), ports, environment, volumes,
 * command, labels, restart, stdin_open, tty, container_name. Everything else is
 * ignored on purpose.
 *
 * Uses the native ext-yaml (yaml_parse); a userland YAML library would violate
 * the "no external PHP dependencies / portable" rule.
 */
final class ComposeParser
{
    /**
     * @return array{
     *   service:string, image:string, containerName:?string,
     *   ports:list<array{host:int,container:int,proto:string}>,
     *   env:list<string>,
     *   volumes:list<array{source:string,target:string,readonly:bool}>,
     *   command:?array<int,string>, labels:array<string,string>,
     *   restart:?string, openStdin:?bool, tty:bool
     * }
     */
    public function parse(string $yaml): array
    {
        if (!function_exists('yaml_parse')) {
            throw new HttpException(500, 'The server is missing the YAML extension.', 'NO_YAML');
        }
        if (trim($yaml) === '') {
            throw new HttpException(422, 'The template is empty.', 'EMPTY_TEMPLATE');
        }

        // yaml_parse emits a warning + returns false on malformed input; silence
        // the warning and turn the false into a clean 422 (template unusable).
        $data = @yaml_parse($yaml);
        if (!is_array($data)) {
            throw new HttpException(422, 'The template is not valid YAML.', 'BAD_YAML');
        }

        $services = $data['services'] ?? null;
        if (!is_array($services) || $services === []) {
            throw new HttpException(422, 'The template defines no services.', 'NO_SERVICE');
        }
        if (count($services) !== 1) {
            throw new HttpException(422, 'The template must define exactly one service.', 'MULTI_SERVICE');
        }

        $name = (string) array_key_first($services);
        $svc  = $services[$name];
        if (!is_array($svc)) {
            throw new HttpException(422, "The service '$name' is malformed.", 'BAD_SERVICE');
        }

        $image = isset($svc['image']) ? trim((string) $svc['image']) : '';
        if ($image === '') {
            throw new HttpException(422, "The service '$name' must specify an image.", 'NO_IMAGE');
        }

        return [
            'service'       => $name,
            'image'         => $image,
            'containerName' => isset($svc['container_name']) ? (string) $svc['container_name'] : null,
            'ports'         => $this->parsePorts($svc['ports'] ?? []),
            'env'           => $this->parseEnv($svc['environment'] ?? []),
            'volumes'       => $this->parseVolumes($svc['volumes'] ?? []),
            'command'       => $this->parseCommand($svc['command'] ?? null),
            'labels'        => $this->parseLabels($svc['labels'] ?? []),
            'restart'       => isset($svc['restart']) ? (string) $svc['restart'] : null,
            // null = unspecified → CreatorService defaults stdin open for the console.
            'openStdin'     => array_key_exists('stdin_open', $svc) ? (bool) $svc['stdin_open'] : null,
            'tty'           => (bool) ($svc['tty'] ?? false),
        ];
    }

    /* ---------------------------------------------------------------- */

    /**
     * Short syntax ("HOST:CONTAINER", "CONTAINER", "IP:HOST:CONTAINER",
     * "CONT/proto", "HOST:CONT/proto") and long syntax
     * ({target, published, protocol}). A missing host port defaults to the
     * container port. Ranges ("8000-8010") are rejected (single container).
     *
     * @return list<array{host:int,container:int,proto:string}>
     */
    private function parsePorts(mixed $ports): array
    {
        if ($ports === [] || $ports === null) {
            return [];
        }
        if (!is_array($ports)) {
            throw new HttpException(422, 'The template\'s "ports" must be a list.', 'BAD_PORTS');
        }

        $out = [];
        foreach ($ports as $entry) {
            if (is_array($entry)) {
                $container = (int) ($entry['target'] ?? 0);
                $host      = (int) ($entry['published'] ?? $entry['target'] ?? 0);
                $proto     = strtolower((string) ($entry['protocol'] ?? 'tcp'));
            } else {
                [$host, $container, $proto] = $this->parseShortPort((string) $entry);
            }
            if ($container < 1 || $container > 65535 || $host < 1 || $host > 65535) {
                throw new HttpException(422, 'Invalid port mapping in the template: ' . json_encode($entry), 'BAD_PORT');
            }
            $proto = ($proto === 'udp') ? 'udp' : 'tcp';
            $out[] = ['host' => $host, 'container' => $container, 'proto' => $proto];
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:string} [host, container, proto] */
    private function parseShortPort(string $spec): array
    {
        $spec  = trim($spec);
        $proto = 'tcp';
        if (str_contains($spec, '/')) {
            [$spec, $proto] = explode('/', $spec, 2);
        }
        if (str_contains($spec, '-')) {
            throw new HttpException(422, "Port ranges are not supported: $spec", 'PORT_RANGE');
        }

        $parts = explode(':', $spec);
        $count = count($parts);
        if ($count === 1) {
            $container = (int) $parts[0];
            $host      = $container;
        } elseif ($count === 2) {
            $host      = (int) $parts[0];
            $container = (int) $parts[1];
        } elseif ($count === 3) {
            // Ignore the bind IP; SGI-Creator publishes on all interfaces.
            $host      = (int) $parts[1];
            $container = (int) $parts[2];
        } else {
            throw new HttpException(422, "Invalid port mapping: $spec", 'BAD_PORT');
        }
        return [$host, $container, $proto];
    }

    /**
     * Environment as a map ({KEY: value}) or a list ("KEY=value"). Returns the
     * Docker `Env` list form ("KEY=value").
     *
     * @return list<string>
     */
    private function parseEnv(mixed $env): array
    {
        if ($env === [] || $env === null) {
            return [];
        }
        $out = [];
        if ($this->isList($env)) {
            foreach ($env as $line) {
                $line = (string) $line;
                if ($line !== '') {
                    $out[] = $line;
                }
            }
        } elseif (is_array($env)) {
            foreach ($env as $key => $value) {
                $out[] = $key . '=' . $this->scalar($value);
            }
        }
        return $out;
    }

    /**
     * Volumes in short syntax ("src:dst[:ro]") or long syntax
     * ({type, source, target, read_only}). The SOURCE is preserved (it carries
     * the resolved %%path%% bind, or a named volume); an anonymous volume has an
     * empty source. The target must be an absolute path.
     *
     * @return list<array{source:string,target:string,readonly:bool}>
     */
    private function parseVolumes(mixed $volumes): array
    {
        if ($volumes === [] || $volumes === null) {
            return [];
        }
        if (!is_array($volumes)) {
            throw new HttpException(422, 'The template\'s "volumes" must be a list.', 'BAD_VOLUMES');
        }

        $out = [];
        foreach ($volumes as $entry) {
            if (is_array($entry)) {
                $source   = trim((string) ($entry['source'] ?? ''));
                $target   = trim((string) ($entry['target'] ?? ''));
                $readonly = (bool) ($entry['read_only'] ?? false);
            } else {
                [$source, $target, $readonly] = $this->parseShortVolume((string) $entry);
            }
            if ($target === '' || $target[0] !== '/') {
                throw new HttpException(422, 'A volume target must be an absolute path: ' . json_encode($entry), 'BAD_VOLUME');
            }
            $out[] = ['source' => $source, 'target' => $target, 'readonly' => $readonly];
        }
        return $out;
    }

    /**
     * Short volume syntax. A leading Windows-drive/absolute host path may itself
     * contain a ':', so we split from the RIGHT into at most [source, target, mode].
     *
     * @return array{0:string,1:string,2:bool} [source, target, readonly]
     */
    private function parseShortVolume(string $entry): array
    {
        $entry = trim($entry);
        $parts = explode(':', $entry);

        // No ':' at all → an anonymous volume "/dst".
        if (count($parts) === 1) {
            return ['', $parts[0], false];
        }

        // Trailing mode? (ro / rw / z / Z ...) — treat "ro" as read-only.
        $readonly = false;
        $last     = end($parts);
        if (preg_match('/^(ro|rw|z|Z|ro,.*|rw,.*)$/', (string) $last)) {
            $readonly = str_contains((string) $last, 'ro');
            array_pop($parts);
        }

        // The container target is the last remaining field; everything before it
        // (re-joined) is the source — this preserves a source that contained ':'.
        $target = array_pop($parts) ?? '';
        $source = implode(':', $parts);

        return [trim($source), trim($target), $readonly];
    }

    /**
     * Command as a string (shell form) or a list (exec form).
     *
     * @return array<int,string>|null
     */
    private function parseCommand(mixed $command): ?array
    {
        if ($command === null || $command === '') {
            return null;
        }
        if (is_array($command)) {
            return array_values(array_map(fn($c) => (string) $c, $command));
        }
        // Shell form — let /bin/sh split it, matching compose semantics.
        return ['/bin/sh', '-c', (string) $command];
    }

    /**
     * Labels as a map ({key: value}) or a list ("key=value").
     *
     * @return array<string,string>
     */
    private function parseLabels(mixed $labels): array
    {
        if ($labels === [] || $labels === null) {
            return [];
        }
        $out = [];
        if ($this->isList($labels)) {
            foreach ($labels as $line) {
                $line = (string) $line;
                $eq   = strpos($line, '=');
                if ($eq === false) {
                    $out[$line] = '';
                } else {
                    $out[substr($line, 0, $eq)] = substr($line, $eq + 1);
                }
            }
        } elseif (is_array($labels)) {
            foreach ($labels as $key => $value) {
                $out[(string) $key] = $this->scalar($value);
            }
        }
        return $out;
    }

    /** A zero-indexed sequential array (YAML sequence) vs a map. */
    private function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    /** Render a YAML scalar as a string (bools as true/false, not 1/''). */
    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }
}
