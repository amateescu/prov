<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Mention;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;
use Prov\Serializer\XmlSerializer;

final class MentionTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testMentionConstruction(): void
    {
        $men = new Mention(
            specificEntity: $this->ex->qualifiedName('e1'),
            generalEntity: $this->ex->qualifiedName('e2'),
            bundle: $this->ex->qualifiedName('b1'),
        );
        $this->assertSame('http://example.org/e1', $men->specificEntity->uri);
        $this->assertSame('http://example.org/e2', $men->generalEntity->uri);
        $this->assertSame('http://example.org/b1', $men->bundle->uri);
    }

    public function testBuilderMentionOf(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->mentionOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2', bundle: 'ex:b1');

        $doc = $builder->build();
        $mentions = $doc->getRecordsByType(Mention::class);
        $this->assertCount(1, $mentions);
        $this->assertSame('http://example.org/b1', $mentions[0]->bundle->uri);
    }

    public function testJsonRoundTrip(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->mentionOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2', bundle: 'ex:b1');

        $serializer = new JsonSerializer();
        $json = $serializer->serialize($builder->build());
        $doc = $serializer->deserialize($json);

        $this->assertCount(1, $doc->getRecordsByType(Mention::class));
        $men = $doc->getRecordsByType(Mention::class)[0];
        $this->assertSame('http://example.org/e1', $men->specificEntity->uri);
        $this->assertSame('http://example.org/e2', $men->generalEntity->uri);
        $this->assertSame('http://example.org/b1', $men->bundle->uri);
    }

    public function testProvNRoundTrip(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->mentionOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2', bundle: 'ex:b1');

        $serializer = new ProvNSerializer();
        $provn = $serializer->serialize($builder->build());
        $this->assertStringContainsString('mentionOf(ex:e1, ex:e2, ex:b1)', $provn);

        $deserializer = new ProvNDeserializer();
        $doc = $deserializer->deserialize($provn);
        $this->assertCount(1, $doc->getRecordsByType(Mention::class));
    }

    public function testXmlRoundTrip(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->mentionOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2', bundle: 'ex:b1');

        $serializer = new XmlSerializer();
        $xml = $serializer->serialize($builder->build());
        $this->assertStringContainsString('prov:mentionOf', $xml);

        $doc = $serializer->deserialize($xml);
        $this->assertCount(1, $doc->getRecordsByType(Mention::class));
    }
}
