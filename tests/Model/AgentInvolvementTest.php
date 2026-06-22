<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Identifier\ProvNamespace;
use Prov\Model\AgentInvolvement;

final class AgentInvolvementTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $agent = $ex->qualifiedName('alice');
        $plan = $ex->qualifiedName('recipe');
        $attributes = Attributes::empty()->with($ex->qualifiedName('role'), 'author');
        $onBehalfOf = [$ex->qualifiedName('acme'), $ex->qualifiedName('parent')];

        $involvement = new AgentInvolvement(
            agent: $agent,
            plan: $plan,
            attributes: $attributes,
            onBehalfOf: $onBehalfOf,
        );

        $this->assertSame($agent, $involvement->agent);
        $this->assertSame($plan, $involvement->plan);
        $this->assertSame($attributes, $involvement->attributes);
        $this->assertSame($onBehalfOf, $involvement->onBehalfOf);
    }

    public function testPlanIsNullableAndChainMayBeEmpty(): void
    {
        $agent = new ProvNamespace('ex', 'http://example.org/')->qualifiedName('alice');

        $involvement = new AgentInvolvement(agent: $agent, plan: null, attributes: new Attributes(), onBehalfOf: []);

        $this->assertNull($involvement->plan);
        $this->assertSame([], $involvement->onBehalfOf);
    }
}
