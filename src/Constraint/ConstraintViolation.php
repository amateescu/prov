<?php

declare(strict_types=1);

namespace Prov\Constraint;

/**
 * A single PROV-CONSTRAINTS rule violation produced by the validator.
 * Carries the rule ID, a human-readable message, and the URI of the
 * offending record when one is available.
 */
readonly class ConstraintViolation
{
    public int $constraintId;
    public string $constraintName;

    public function __construct(
        public ConstraintId $constraint,
        public string $message,
        public ?string $recordIdentifier = null,
    ) {
        $this->constraintId = $this->constraint->value;
        $this->constraintName = $this->constraint->constraintName();
    }
}
