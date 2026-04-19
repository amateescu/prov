<?php

declare(strict_types=1);

namespace Prov\Exception;

use Prov\Constraint\ConstraintViolationList;

/**
 * Thrown by ConstraintViolationList::throwIfInvalid() when a document has
 * at least one PROV-CONSTRAINTS violation.
 */
class ConstraintViolationException extends ProvException
{
    public function __construct(
        public readonly ConstraintViolationList $violations,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $this->defaultMessage());
    }

    private function defaultMessage(): string
    {
        $count = count($this->violations);
        $first = $this->violations->getViolations()[0] ?? null;
        $summary = $first !== null ? " First: [C{$first->constraintId}] {$first->message}" : '';
        return "Document has {$count} PROV-CONSTRAINTS violation(s).{$summary}";
    }
}
