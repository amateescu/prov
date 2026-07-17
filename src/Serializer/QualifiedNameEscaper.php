<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;

/**
 * Encodes and decodes the PROV grammar's `PN_CHARS_ESC` backslash escapes, which
 * let a qualified-name local part carry delimiter punctuation (`( ) [ ] ' = , ; : .`).
 *
 * PROV-N requires these escapes so a local name does not break out of its token,
 * and ProvToolbox's PROV-JSON uses the same scheme inside its `prefix:local`
 * strings. Both serializers share this logic so a name's canonical local part is
 * identical across formats: the escaped form is purely a serialization detail,
 * not part of the resource identity.
 *
 * @internal
 */
final class QualifiedNameEscaper
{
    /**
     * Characters escaped at every position. `.` and `-` are escaped only where
     * the grammar requires it (see escape()); ProvToolbox likewise leaves a
     * medial `-` bare.
     */
    private const string ESCAPE_ALWAYS = "()[]'=,;:";

    /**
     * Characters decoded on input: the full `PN_CHARS_ESC` set, so an escaped
     * `-` or medial `.` produced by some other writer is still recovered.
     */
    private const string DECODE = "='(),-:;[].";

    /**
     * Backslash-escapes `PN_CHARS_ESC` punctuation in a local name. Delimiter
     * punctuation is escaped at every position; `.` and `-` only where
     * PN_LOCAL cannot carry them bare (`.` first or last, `-` first), so the
     * common dotted-name shape keeps its familiar lexical form.
     *
     * A literal backslash is rejected: PN_CHARS_ESC has no escape for backslash
     * itself, so decode() cannot tell an escaped punctuation mark from a raw
     * backslash left untouched, and a name carrying one would alias a different
     * name on round trip. The check rides the same scan that does the escaping,
     * so a clean name still returns on the fast path at no extra cost.
     *
     * @throws \InvalidArgumentException
     *   When the local name contains a backslash.
     */
    public static function escape(string $local): string
    {
        // Fast path: when the name carries none of the punctuation that could
        // ever need escaping (and no backslash), a single C-level scan returns
        // it untouched and skips the per-character loop. Common shape (`e123`).
        if (strpbrk($local, self::ESCAPE_ALWAYS . '.-\\') === false) {
            return $local;
        }
        $out = '';
        $len = strlen($local);
        for ($i = 0; $i < $len; $i++) {
            $c = $local[$i];
            if ($c === '\\') {
                throw new \InvalidArgumentException(
                    "Local name '{$local}' contains a backslash, which cannot be represented in the PROV grammar.",
                );
            }
            $escape =
                str_contains(self::ESCAPE_ALWAYS, $c)
                || $c === '.' && ($i === 0 || $i === ($len - 1))
                || $c === '-' && $i === 0;
            $out .= $escape ? '\\' . $c : $c;
        }
        return $out;
    }

    /**
     * Resolves a serialized `prefix:local` string and decodes the escapes in the
     * local part, so a name read back from a format that escapes matches the
     * same name read from one that does not. Every read side that resolves a
     * document-side name (the PROV-JSON deserializer, the scanner) goes through
     * this, so they report identical identifiers for identical input.
     *
     * @throws \Prov\Exception\NamespaceException
     *   When the identifier does not resolve against the given namespaces.
     */
    public static function resolveDecoded(string $raw, NamespaceManager $nsManager): QualifiedName
    {
        $qn = $nsManager->resolve($raw);
        // An escape can only be present if the raw string carried a backslash;
        // skip the decode pass (and its allocation) for the common bare name.
        if (!str_contains($raw, '\\')) {
            return $qn;
        }
        $decoded = self::decode($qn->localPart);
        if ($decoded === $qn->localPart) {
            return $qn;
        }
        return new QualifiedName($qn->namespace, $decoded);
    }

    /**
     * Decodes `PN_CHARS_ESC` backslash escapes (`\,` -> `,`) in a local name,
     * leaving any other backslash sequence untouched.
     */
    public static function decode(string $local): string
    {
        if (!str_contains($local, '\\')) {
            return $local;
        }
        $out = '';
        $len = strlen($local);
        for ($i = 0; $i < $len; $i++) {
            $c = $local[$i];
            if ($c === '\\' && ($i + 1) < $len && str_contains(self::DECODE, $local[$i + 1])) {
                $out .= $local[$i + 1];
                $i++;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }
}
