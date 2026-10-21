<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule was violated (slot taken, outside opening hours, ...).
 * Rendered as a 422 JSON error with a human-readable message.
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
