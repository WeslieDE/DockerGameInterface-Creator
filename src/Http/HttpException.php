<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Http;

use RuntimeException;

/**
 * Carries an HTTP status code so the router can turn it into a JSON error.
 *
 * The frontend (index.html) surfaces the message verbatim, so every message
 * here is written as one plain sentence a panel user can read.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param int    $status    HTTP status code sent to the client.
     * @param string $message   One-sentence, user-facing explanation.
     * @param string $errorCode Optional machine-readable code (frontend "Reported as").
     *                          Named $errorCode, not $code, because Exception already
     *                          reserves a typed $code property.
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $errorCode = '',
    ) {
        parent::__construct($message);
    }
}
