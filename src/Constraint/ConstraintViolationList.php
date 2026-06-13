<?php

declare(strict_types=1);

namespace Prov\Constraint;

use Prov\Exception\ConstraintViolationException;

/**
 * The result of running the PROV-CONSTRAINTS validator: either empty
 * (valid) or a list of violations. Chain `throwIfInvalid()` for an
 * exception-based flow, iterate the list directly (it is `IteratorAggregate`),
 * or pull the array via `getViolations()`.
 *
 * @implements \IteratorAggregate<int, \Prov\Constraint\ConstraintViolation>
 */
class ConstraintViolationList implements \Countable, \IteratorAggregate
{
    /** @var list<\Prov\Constraint\ConstraintViolation> */
    private array $violations = [];

    /**
     * Records a violation. Called by the validator as it checks each rule.
     */
    public function add(ConstraintViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /**
     * Whether the document passed every implemented check. Unsupported
     * constraints (see `ConstraintValidator::unsupportedConstraints`)
     * can still be violated without affecting this result.
     */
    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * Throws a ConstraintViolationException if this list contains any violations.
     *
     * @throws \Prov\Exception\ConstraintViolationException
     */
    public function throwIfInvalid(): void
    {
        if ($this->violations !== []) {
            throw new ConstraintViolationException($this);
        }
    }

    /**
     * @return list<\Prov\Constraint\ConstraintViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * @return list<\Prov\Constraint\ConstraintViolation>
     */
    public function getViolationsByConstraint(ConstraintId|int $id): array
    {
        $intId = $id instanceof ConstraintId ? $id->value : $id;
        return array_values(array_filter(
            $this->violations,
            static fn(ConstraintViolation $v): bool => $v->constraintId === $intId,
        ));
    }

    /**
     * Total number of violations recorded.
     */
    public function count(): int
    {
        return count($this->violations);
    }

    /**
     * @return \ArrayIterator<int, \Prov\Constraint\ConstraintViolation>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->violations);
    }
}
