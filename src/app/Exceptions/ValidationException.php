<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Domain validation exception with structured error details.
 *
 * Thrown by service-layer validation when input fails business rules.
 * Caught by ErrorHandlerMiddleware to produce {"error": "...", "details": {...}} responses.
 */
class ValidationException extends \InvalidArgumentException
{
    /** @var array<string, string> Field-level error details */
    private array $details;

    /**
     * @param string $message Human-readable summary
     * @param array<string, string> $details Field-level error messages
     */
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /**
     * @return array<string, string>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
