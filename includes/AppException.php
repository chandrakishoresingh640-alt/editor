<?php
declare(strict_types=1);

/**
 * A failure that is safe to show to the person using the site.
 */
final class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 400,
        private string $errorCode = 'error',
        private array $extra = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function extra(): array
    {
        return $this->extra;
    }
}
