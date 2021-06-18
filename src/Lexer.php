<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Exception\NgxParserException;
use Nelexa\NginxParser\Exception\NgxParserIOException;
use Nelexa\NginxParser\Exception\NgxParserSyntaxException;
use Nelexa\NginxParser\Util\StringUtil;

class Lexer
{
    /** @var array<string, callable> */
    private $externalLexers = [];

    /**
     * Generates tokens from an nginx config file.
     *
     * @param string $filename
     *
     * @throws NgxParserException
     *
     * @return \Generator
     */
    public function lex(string $filename): \Generator
    {
        if (!file_exists($filename)) {
            throw new NgxParserIOException(sprintf("File '%s' not found", $filename), $filename);
        }

        if (!is_file($filename)) {
            throw new NgxParserIOException(sprintf("'%s' is not file", $filename), $filename);
        }

        set_error_handler(
            static function (int $errorNumber, string $errorString) use ($filename): ?bool {
                throw new NgxParserIOException($errorString, $filename, null, null, $errorNumber);
            }
        );
        $handle = fopen($filename, 'rb');
        restore_error_handler();

        $it = $this->lexFileObject($handle);
        $it = $this->balanceBraces($it, $filename);

        foreach ($it as [$token, $line, $quoted]) {
            yield [$token, $line, $quoted];
        }

        fclose($handle);
    }

    /**
     * Generates token tuples from an nginx config file object.
     *
     * @param resource $fp
     *
     * @return \Generator Yields 3-tuples like (token, lineno, quoted)
     */
    private function lexFileObject($fp): ?\Generator
    {
        $it = $this->iterateLineCount(
            $this->iterateEscape(
                $this->iterateChars(
                    $this->iterateFileContents($fp)
                )
            )
        );

        if ($it === null) {
            return;
        }

        $token = '';
        $tokenLine = 0;
        $nextTokenIsDirective = true;

        foreach ($it as [$char, $line]) {
            if (StringUtil::isSpace($char)) {
                if ($token) {
                    yield [$token, $tokenLine, false];

                    if ($nextTokenIsDirective && isset($this->externalLexers[$token])) {
                        foreach ($this->externalLexers[$token]($it, $token) as $customLexerToken) {
                            yield $customLexerToken;

                            $nextTokenIsDirective = true;
                        }
                    } else {
                        $nextTokenIsDirective = false;
                    }
                    $token = '';
                }

                // disregard until char isn't a whitespace character
                while (StringUtil::isSpace($char)) {
                    $it->next();
                    [$char, $line] = $it->current();
                }
            }

            // if starting comment
            if (!$token && $char === '#') {
                while (!str_ends_with($char, "\n")) {
                    $token .= $char;
                    $it->next();
                    [$char] = $it->current();
                }
                yield [$token, $line, false];
                $token = '';

                continue;
            }

            if (!$token) {
                $tokenLine = $line;
            }

            // handle parameter expansion syntax (ex: "${var[@]}")
            if ($token !== '' && $token[-1] === '$' && $char === '{') {
                $nextTokenIsDirective = false;
                while ($token[-1] !== '}' && !StringUtil::isSpace($char)) {
                    $token .= $char;
                    $it->next();
                    [$char, $line] = $it->current();
                }
            }

            // if a quote is found, add the whole string to the token buffer
            if ($char === '"' || $char === "'") {
                // if a quote is inside a token, treat it like any other char
                if ($token !== '') {
                    $token .= $char;

                    continue;
                }

                $quote = $char;
                $it->next();
                [$char] = $it->current();
                while ($char !== $quote) {
                    $token .= $char === '\\' . $quote ? $quote : $char;
                    $it->next();
                    [$char] = $it->current();
                }

                yield [$token, $tokenLine, true];  // true because this is in quotes

                // handle quoted external directives
                if ($nextTokenIsDirective && isset($this->externalLexers[$token])) {
                    foreach ($this->externalLexers[$token]($it, $token) as $customLexerToken) {
                        yield $customLexerToken;

                        $nextTokenIsDirective = true;
                    }
                } else {
                    $nextTokenIsDirective = false;
                }
                $token = '';

                continue;
            }

            // handle special characters that are treated like full tokens
            if ($char === '{' || $char === '}' || $char === ';') {
                // if token complete yield it and reset token buffer

                if ($token !== '') {
                    yield [$token, $tokenLine, false];
                    $token = '';
                }

                // this character is a full token so yield it now
                yield [$char, $line, false];
                $nextTokenIsDirective = true;

                continue;
            }
            // append char to the token buffer
            $token .= $char;
        }
    }

    /**
     * @param resource $fp
     *
     * @return \Generator|null
     */
    private function iterateFileContents($fp): ?\Generator
    {
        while (($str = fread($fp, 1024)) !== false) {
            yield $str;

            if (feof($fp)) {
                break;
            }
        }
    }

    private function iterateChars(iterable $it): ?\Generator
    {
        foreach ($it as $line) {
            $chars = mb_str_split($line, 1, 'UTF-8');
            foreach ($chars as $char) {
                yield $char;
            }
        }
    }

    private function iterateEscape(\Iterator $it): ?\Generator
    {
        foreach ($it as $char) {
            if ($char === '\\') {
                $it->next();
                $char .= $it->current();
            }
            yield $char;
        }
    }

    private function iterateLineCount(iterable $it): ?\Generator
    {
        $line = 1;
        foreach ($it as $char) {
            if (str_ends_with($char, "\n")) {
                $line++;
            }
            yield [$char, $line];
        }
    }

    /**
     * Raises syntax errors if braces aren't balanced.
     *
     * @param array  $tokens
     * @param string $filename
     *
     * @throws NgxParserSyntaxException
     *
     * @return \Generator
     */
    private function balanceBraces(iterable $tokens, string $filename): \Generator
    {
        $depth = 0;

        foreach ($tokens as [$token, $line, $quoted]) {
            if ($token === '}' && !$quoted) {
                $depth--;
            } elseif ($token === '{' && !$quoted) {
                $depth++;
            }

            // raise error if we ever have more right braces than left
            if ($depth < 0) {
                throw new NgxParserSyntaxException('unexpected "}"', $filename, $line);
            }

            yield [$token, $line, $quoted];
        }
    }

    public function registerExternalLexer(callable $lexer, array $directives): void
    {
        foreach ($directives as $directive) {
            $this->externalLexers[$directive] = $lexer;
        }
    }
}
