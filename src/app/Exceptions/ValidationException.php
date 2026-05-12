<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when input validation fails.
 * Carries both a human-readable message and structured details for the API response.
 */
class ValidationException extends \InvalidArgumentException
{
    /**
     * @param string $message Human-readable summary
     * @param array<string, mixed> $details Structured validation error details
     */
    public function __construct(
        string $message,
        private array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
