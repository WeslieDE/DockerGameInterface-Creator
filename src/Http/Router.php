<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Http;

use tk\weslie\SgiCreator\Auth\PasswordAuth;
use tk\weslie\SgiCreator\Docker\DockerException;
use tk\weslie\SgiCreator\Service\AdminService;
use tk\weslie\SgiCreator\Service\CreatorService;
use tk\weslie\SgiCreator\Template\TemplateRepository;

/**
 * Front controller for the API the frontend (index.html) speaks:
 *
 *   POST   /api/auth              password → session token       (no auth header)
 *   GET    /api/templates         the catalogue of SGI templates (Bearer)
 *   POST   /api/containers        create + start a container      (Bearer)
 *   GET    /api/servers           every sgi.token container       (Bearer)
 *   DELETE /api/servers/{token}   delete container + volume + backups (Bearer)
 *
 * Errors are returned as {"error","code"} with the status the frontend maps to
 * its own wording (the `error` text always wins).
 */
final class Router
{
    public function __construct(
        private readonly PasswordAuth $auth,
        private readonly TemplateRepository $templates,
        private readonly CreatorService $creator,
        private readonly AdminService $admin,
    ) {}

    public function dispatch(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path   = $this->path();
            $this->route($method, $path);
        } catch (HttpException $e) {
            $this->error($e->status, $e->getMessage(), $e->errorCode);
        } catch (DockerException $e) {
            $this->error(502, 'The panel could not reach the Docker daemon.', 'DOCKER_UNREACHABLE');
        } catch (\Throwable $e) {
            $this->error(500, 'The backend hit an internal error.', 'INTERNAL');
        }
    }

    /* ---------------------------------------------------------------- */

    private function route(string $method, string $path): void
    {
        // ---- Login (the only unauthenticated route) ----
        if ($method === 'POST' && $path === '/auth') {
            $body = $this->jsonBody();
            $pw   = (string) ($body['password'] ?? '');
            if ($pw === '') {
                throw new HttpException(401, 'Please enter the password.', 'NO_PASSWORD_GIVEN');
            }
            $this->json(['token' => $this->auth->login($pw)]);
            return;
        }

        // Everything below requires a valid session.
        $this->auth->requireSession($this->auth->extractBearer($_SERVER));

        // ---- Template catalogue ----
        if ($method === 'GET' && $path === '/templates') {
            $entries = array_map(
                static fn($t) => $t->toCatalogueEntry(),
                $this->templates->catalogue()
            );
            // The frontend expects a JSON array, not an object.
            $this->json($entries);
            return;
        }

        // ---- Create + start ----
        if ($method === 'POST' && $path === '/containers') {
            $body       = $this->jsonBody();
            $templateId = trim((string) ($body['templateId'] ?? ''));
            if ($templateId === '') {
                throw new HttpException(400, 'No template was selected.', 'NO_TEMPLATE_ID');
            }
            $tpl    = $this->templates->require($templateId);
            $result = $this->creator->create($tpl);
            $this->json($result, 201);
            return;
        }

        // ---- Manage: list every SGI-managed server ----
        if ($method === 'GET' && $path === '/servers') {
            $this->json($this->admin->listServers());
            return;
        }

        // ---- Manage: delete a server (container + volume + backups) ----
        if ($method === 'DELETE' && preg_match('#^/servers/([^/]+)$#', $path, $m) === 1) {
            $token = rawurldecode($m[1]);
            if ($token === '') {
                throw new HttpException(400, 'No server token was given.', 'NO_TOKEN');
            }
            $this->admin->deleteServer($token);
            $this->json(['status' => 'deleted', 'token' => $token]);
            return;
        }

        throw new HttpException(404, 'No such endpoint.', 'NO_ROUTE');
    }

    /* ---------------------------------------------------------------- */

    /** Path relative to /api, e.g. "/templates". */
    private function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rel = preg_replace('#^.*?/api#', '', $uri, 1);
        $rel = rtrim($rel, '/');
        return $rel === '' ? '/' : $rel;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'The request body was not valid JSON.', 'BAD_JSON');
        }
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed>|list<mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function error(int $status, string $message, string $code = ''): void
    {
        $body = ['error' => $message];
        if ($code !== '') {
            $body['code'] = $code;
        }
        $this->json($body, $status);
    }
}
