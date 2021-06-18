<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Util;

class JsonFormatter
{
    /** @var int */
    private $indent;

    /** @var string */
    private $newLine;

    /** @var int|string */
    private $flags;

    /**
     * @param int    $indent
     * @param string $newLine
     * @param int    $flags
     */
    public function __construct(
        int $indent = 0,
        string $newLine = \PHP_EOL,
        int $flags = \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_UNESCAPED_SLASHES
    ) {
        $this->indent = $indent;
        $this->newLine = $newLine;
        $this->flags = $flags;
    }

    /**
     * @param mixed $payload
     *
     * @return string
     */
    public function format($payload): string
    {
        $flags = $this->flags;
        if ($this->indent === 4) {
            $flags |= \JSON_PRETTY_PRINT;
        }
        $json = json_encode($payload, $flags);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not valid payload.',
                $json
            ));
        }
        if ($this->indent !== 0 && $this->indent !== 4) {
            $json = $this->doFormat($json);
        }

        return $json;
    }

    private function doFormat(string $json): string
    {
        $iterateChars = static function (string $string) {
            $chars = mb_str_split($string, 1, 'UTF-8');
            foreach ($chars as $char) {
                yield $char;
            }
        };
        $iterateEscape = static function (\Iterator $it) {
            foreach ($it as $char) {
                if ($char === '\\') {
                    $it->next();
                    $char .= $it->current();
                }
                yield $char;
            }
        };
        $it = $iterateEscape($iterateChars($json));

        $indentLevel = 0;
        $withinStringLiteral = false;
        $stringLiteral = '';
        $output = '';
        $indentStr = str_repeat(' ', $this->indent);

        foreach ($it as $character) {
            if ($character === '"') {
                $withinStringLiteral = !$withinStringLiteral;
            }

            if ($withinStringLiteral) {
                $stringLiteral .= $character;

                continue;
            }

            if ($stringLiteral !== '') {
                $output .= $stringLiteral . $character;
                $stringLiteral = '';

                continue;
            }

            if (StringUtil::isSpace($character)) {
                continue;
            }

            if ($character === ':') {
                $output .= ': ';

                continue;
            }

            if ($character === ',') {
                $output .= $character . $this->newLine . str_repeat($indentStr, $indentLevel);

                continue;
            }

            if ($character === '{' || $character === '[') {
                ++$indentLevel;

                $output .= $character . $this->newLine . str_repeat($indentStr, $indentLevel);

                continue;
            }

            if ($character === '}' || $character === ']') {
                --$indentLevel;

                $trimmed = rtrim($output);
                $previousNonWhitespaceCharacter = mb_substr($trimmed, -1, 1, 'UTF-8');

                if ($previousNonWhitespaceCharacter === '{' || $previousNonWhitespaceCharacter === '[') {
                    $output = $trimmed . $character;

                    continue;
                }

                $output .= $this->newLine . str_repeat($indentStr, $indentLevel);
            }

            $output .= $character;
        }

        return $output;
    }
}
