<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Docker;

use RuntimeException;

/** A failure talking to the Docker Engine API. Carries the HTTP status Docker returned. */
final class DockerException extends RuntimeException
{
    public function __construct(string $message, public readonly int $dockerStatus = 0)
    {
        parent::__construct($message);
    }
}
