<?php

namespace AsadbekRahimov\EimzoIntegration\Exceptions;

use RuntimeException;
use Throwable;

class EimzoException extends RuntimeException
{
    protected array $payload = [];

    public function __construct(string $message = '', array $payload = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
