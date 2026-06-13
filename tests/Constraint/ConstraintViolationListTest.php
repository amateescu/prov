<?php

declare(strict_types=1);

namespace Prov\Tests\Constraint;

use PHPUnit\Framework\TestCase;
use Prov\Constraint\ConstraintId;
use Prov\Constraint\ConstraintViolation;
use Prov\Constraint\ConstraintViolationList;

final class ConstraintViolationListTest extends TestCase
{
    public function testIsIterable(): void
    {
        $list = new ConstraintViolationList();
        $a = new ConstraintViolation(ConstraintId::UniqueGeneration, 'first');
        $b = new ConstraintViolation(ConstraintId::UniqueGeneration, 'second');
        $list->add($a);
        $list->add($b);

        $collected = [];
        foreach ($list as $violation) {
            $collected[] = $violation->message;
        }

        $this->assertSame(['first', 'second'], $collected);
        $this->assertSame([$a, $b], iterator_to_array($list));
    }

    public function testEmptyListIteratesEmpty(): void
    {
        $this->assertSame([], iterator_to_array(new ConstraintViolationList()));
    }
}
