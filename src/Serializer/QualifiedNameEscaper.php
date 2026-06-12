<?php

declare(strict_types=1);

namespace Prov\Serializer;

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
     */
    public static function escape(string $local): string
    {
        $out = '';
        $len = strlen($local);
        for ($i = 0; $i < $len; $i++) {
            $c = $local[$i];
            $escape =
                str_contains(self::ESCAPE_ALWAYS, $c)
                || $c === '.' && ($i === 0 || $i === ($len - 1))
                || $c === '-' && $i === 0;
            $out .= $escape ? '\\' . $c : $c;
        }
        return $out;
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
