<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Serializer\QualifiedNameEscaper;

/**
 * Unit coverage for the shared PN_CHARS_ESC encoder/decoder used by the PROV-N
 * and PROV-JSON serializers.
 */
final class QualifiedNameEscaperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeProvider(): iterable
    {
        yield 'no punctuation' => ['plain', 'plain'];
        yield 'empty' => ['', ''];
        yield 'single dash' => ['-', '\\-'];
        yield 'two dots' => ['..', '\\.\\.'];
        yield 'uuid' => ['5f2c1c1e-9b3a-4c50-9d6d-6b7e1c4e0c8a', '5f2c1c1e-9b3a-4c50-9d6d-6b7e1c4e0c8a'];
        yield 'comma' => ['a,b', 'a\\,b'];
        yield 'brackets' => ['a[b]c', 'a\\[b\\]c'];
        yield 'parens and quote' => ["a'(b)", "a\\'\\(b\\)"];
        yield 'equals colon semicolon' => ['a=b:c;d', 'a\\=b\\:c\\;d'];
        yield 'medial dot is bare' => ['node.article.body', 'node.article.body'];
        yield 'leading dot' => ['.cfg', '\\.cfg'];
        yield 'trailing dot' => ['cfg.', 'cfg\\.'];
        yield 'single dot' => ['.', '\\.'];
        yield 'medial dash is bare' => ['100-entity', '100-entity'];
        yield 'leading dash' => ['-x', '\\-x'];
        yield 'trailing dash is bare' => ['x-', 'x-'];
        yield 'all escapable' => ["='(),:;[].", "\\=\\'\\(\\)\\,\\:\\;\\[\\]\\."];
    }

    public function testEscapeRejectsBackslash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QualifiedNameEscaper::escape('a\\b');
    }

    #[DataProvider('escapeProvider')]
    public function testEscape(string $input, string $expected): void
    {
        $this->assertSame($expected, QualifiedNameEscaper::escape($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function decodeProvider(): iterable
    {
        yield 'no backslash' => ['plain', 'plain'];
        yield 'comma' => ['a\\,b', 'a,b'];
        yield 'dash is in the decode set' => ['a\\-b', 'a-b'];
        yield 'all escapable' => ["\\=\\'\\(\\)\\,-\\:\\;\\[\\]\\.", "='(),-:;[]."];
        yield 'non-escapable sequence is preserved' => ['a\\b', 'a\\b'];
        yield 'trailing lone backslash is preserved' => ['a\\', 'a\\'];
    }

    #[DataProvider('decodeProvider')]
    public function testDecode(string $input, string $expected): void
    {
        $this->assertSame($expected, QualifiedNameEscaper::decode($input));
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function roundTripProvider(): iterable
    {
        yield 'comma' => ['a,b'];
        yield 'all escapable' => ["='(),:;[]."];
        yield 'dot' => ['file.txt'];
        yield 'leading dot' => ['.hidden'];
        yield 'trailing dot' => ['v1.'];
        yield 'leading dash' => ['-flag'];
        yield 'mixed' => ['weird:name(with).bits'];
    }

    #[DataProvider('roundTripProvider')]
    public function testDecodeReversesEscape(string $local): void
    {
        $this->assertSame($local, QualifiedNameEscaper::decode(QualifiedNameEscaper::escape($local)));
    }
}
