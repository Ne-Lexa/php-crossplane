<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Util;

class StringUtil
{
    public static function isSpace(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        if (\function_exists('ctype_space')) {
            return ctype_space($text);
        }

        return $text !== '' && preg_match('~\S~', $text) === 0;
    }

    /**
     * Replaces ill-formed UTF-8 byte sequences with the Unicode
     * replacement character (U+FFFD).
     *
     * @param string $text
     *
     * @return string
     */
    public static function scrubUtf8(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $hasSubstituteCharacter = \function_exists('mb_substitute_character');
        $substituteCharacter = null;
        if ($hasSubstituteCharacter) {
            $current = \mb_substitute_character();
            if ($current !== false) {
                $substituteCharacter = $current;
            }
            \mb_substitute_character(0xFFFD);
        }

        try {
            if (\function_exists('mb_scrub')) {
                return (string) \mb_scrub($text, 'UTF-8');
            }

            return (string) \mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            if ($substituteCharacter !== null) {
                \mb_substitute_character($substituteCharacter);
            }
        }
    }

    public static function enquote(string $arg): string
    {
        if (!self::needsQuotes($arg)) {
            return $arg;
        }

        return "'"
            . str_replace(
                ['\\', "\n", "\r", "\t", "\v", "\e", "\f", "'", '\\\\'],
                ['\\\\', '\n', '\r', '\t', '\v', '\e', '\f', "\\'", '\\'],
                $arg
            )
            . "'";
    }

    private static function escape(string $string): \Generator
    {
        $prev = '';
        $char = '';
        $chars = mb_str_split($string, 1, 'UTF-8');
        foreach ($chars as $char) {
            if ($prev === '\\' || $prev . $char === '${') {
                $prev .= $char;
                yield $prev;

                continue;
            }
            if ($prev === '$') {
                yield $prev;
            }
            if ($char !== '\\' && $char !== '$') {
                yield $char;
            }
            $prev = $char;
        }
        if ($char === '\\' || $char === '$') {
            yield $char;
        }
    }

    private static function needsQuotes(string $string): bool
    {
        if ($string === '') {
            return true;
        }

        // lexer should throw an error when variable expansion syntax
        // is messed up, but just wrap it in quotes for now I guess
        $chars = self::escape($string);

        // arguments can't start with variable expansion syntax
        $char = $chars->current();
        if (self::isSpace($char) || \in_array($char, ['{', '}', ';', '"', "'", '${'], true)) {
            return true;
        }
        $chars->next();

        $expanding = false;
        while ($chars->valid()) {
            $char = $chars->current();
            if (self::isSpace($char) || \in_array($char, ['{', ';', '"', "'"], true)) {
                return true;
            }

            if ($char === ($expanding ? '${' : '}')) {
                return true;
            }

            if ($char === ($expanding ? '}' : '${')) {
                $expanding = !$expanding;
            }
            $chars->next();
        }

        return \in_array($char, ['\\', '$'], true) || $expanding;
    }
}
