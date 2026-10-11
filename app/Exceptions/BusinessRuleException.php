<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain / business-rule violation carrying a stable, machine-readable code.
 * Rendered as 422 by the API exception handler (architecture §7, §20).
 *
 * @example throw new BusinessRuleException('EP is not editable.', 'EP_NOT_EDITABLE');
 */
class BusinessRuleException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
