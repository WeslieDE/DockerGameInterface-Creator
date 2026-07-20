<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Auth;

use tk\weslie\SgiCreator\Http\HttpException;

/**
 * Password-only authentication, fully stateless.
 *
 * There is no user store and no session store. Login (POST /api/auth) checks the
 * submitted password against the panel password supplied via the SGIC_PASSWORD
 * environment variable. On success it hands back a SESSION TOKEN that is simply a
 * keyed HMAC of a fixed message — deterministic, so any later request can be
 * validated by recomputing it and comparing in constant time. Nothing is stored
 * server-side; the token is only ever as strong as the password behind it.
 *
 * Because the token is derived from the password, it does not rotate: leaking it
 * is equivalent to leaking the password. That matches SGI's own model — the token
 * guards WEB access, not host access. Use a long, random panel password.
 */
final class PasswordAuth
{
    /** Fixed HMAC message — the password is the key, so this is just a domain tag. */
    private const SESSION_MESSAGE = 'tk.weslie.SgiCreator/session/v1';

    /** @param string $password The panel password (from SGIC_PASSWORD); '' = unconfigured. */
    public function __construct(
        private readonly string $password,
    ) {}

    /**
     * Verify the login password and mint a session token.
     *
     * @throws HttpException 500 (unconfigured), 401 (wrong password)
     */
    public function login(string $submitted): string
    {
        $this->assertConfigured();
        if (!hash_equals($this->password, $submitted)) {
            throw new HttpException(401, 'Wrong password.', 'BAD_PASSWORD');
        }
        return $this->sessionToken();
    }

    /**
     * Validate a Bearer session token from an authenticated request.
     *
     * @throws HttpException 500 (unconfigured), 401 (missing/invalid token)
     */
    public function requireSession(string $bearer): void
    {
        $this->assertConfigured();
        if ($bearer === '' || !hash_equals($this->sessionToken(), $bearer)) {
            throw new HttpException(401, 'Your session has expired. Please sign in again.', 'BAD_SESSION');
        }
    }

    /**
     * Read the Bearer token from the request headers.
     * $server is typically $_SERVER.
     *
     * @param array<string,mixed> $server
     */
    public function extractBearer(array $server): string
    {
        $header = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            // Some Apache setups drop Authorization from $_SERVER.
            $all    = apache_request_headers();
            $header = (string) ($all['Authorization'] ?? $all['authorization'] ?? '');
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /* ---------------------------------------------------------------- */

    private function assertConfigured(): void
    {
        if ($this->password === '') {
            throw new HttpException(
                500,
                'The panel password is not configured. Set the SGIC_PASSWORD environment variable.',
                'NO_PASSWORD'
            );
        }
    }

    private function sessionToken(): string
    {
        return hash_hmac('sha256', self::SESSION_MESSAGE, $this->password);
    }
}
