<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Ext;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\LuaBlockParserSyntaxException;
use Nelexa\NginxParser\Util\EmplaceIterator;
use Nelexa\NginxParser\Util\StringUtil;

/**
 * This plugin adds special handling for Lua code block directives (*_by_lua_block)
 * We don't need to handle non-block or file directives because those are parsed
 * correctly by base Crossplane functionality.
 */
class LuaBlockPlugin implements CrossplaneExtension
{
    private $directives = [
        'access_by_lua_block',
        'balancer_by_lua_block',
        'body_filter_by_lua_block',
        'content_by_lua_block',
        'header_filter_by_lua_block',
        'init_by_lua_block',
        'init_worker_by_lua_block',
        'log_by_lua_block',
        'rewrite_by_lua_block',
        'set_by_lua_block',
        'ssl_certificate_by_lua_block',
        'ssl_session_fetch_by_lua_block',
        'ssl_session_store_by_lua_block',
    ];

    /**
     * @param Crossplane $crossplane
     *
     * @return mixed
     */
    public function registerExtension(Crossplane $crossplane): void
    {
        $crossplane->lexer()->registerExternalLexer([$this, 'lex'], $this->directives);
        $crossplane->builder()->registerExternalBuilder([$this, 'build'], $this->directives);
    }

    /**
     * @param \Iterator $charIterator
     * @param string    $directive
     *
     * @throws LuaBlockParserSyntaxException
     *
     * @return \Generator|null
     */
    public function lex(\Iterator $charIterator, string $directive): ?\Generator
    {
        if ($directive === 'set_by_lua_block') {
            // https://github.com/openresty/lua-nginx-module#set_by_lua_block
            // The sole *_by_lua_block directive that has an arg
            $arg = '';
            foreach (new \NoRewindIterator($charIterator) as [$char, $line]) {
                if (StringUtil::isSpace($char)) {
                    if ($arg) {
                        yield [$arg, $line, false];

                        break;
                    }
                    while (StringUtil::isSpace($char)) {
                        $charIterator->next();
                        [$char,] = $charIterator->current();
                    }
                }
                $arg .= $char;
            }
        }

        $depth = 0;
        $token = '';

        // check that Lua block starts correctly
        while (true) {
            if (!$charIterator->valid()) {
                return;
            }
            $charIterator->next();
            [$char, $line] = $charIterator->current();
            if (!StringUtil::isSpace($char)) {
                break;
            }
        }

        if ($char !== '{') {
            $reason = 'expected { to start Lua block';

            throw new LuaBlockParserSyntaxException($reason, '', $line);
        }

        $depth++;

        $charIterator = new EmplaceIterator($charIterator);
        $charIterator->next();
        // Grab everything in Lua block as a single token
        // and watch for curly brace '{' in strings
        foreach ($charIterator as [$char, $line]) {
            if ($char === '-') {
                [$prevChar, $prevLine] = [$char, $line];
                $charIterator->next();
                [$char, $commentLine] = $charIterator->current();
                /** @noinspection NotOptimalIfConditionsInspection */
                if ($char === '-') {
                    $token .= '-';
                    while ($char !== "\n") {
                        $token .= $char;
                        $charIterator->next();
                        [$char, $line] = $charIterator->current();
                    }
                } else {
                    $charIterator->putBack([$char, $commentLine]);
                    [$char, $line] = [$prevChar, $prevLine];
                }
            } elseif ($char === '{') {
                ++$depth;
            } elseif ($char === '}') {
                --$depth;
            } elseif (\in_array($char, ['"', "'"], true)) {
                $quote = $char;
                $token .= $quote;
                $charIterator->next();
                [$char, $line] = $charIterator->current();
                while ($char !== $quote) {
                    $token .= $char;
                    $charIterator->next();
                    [$char, $line] = $charIterator->current();
                }
            }

            if ($depth < 0) {
                $reason = 'unxpected "}"';

                throw new LuaBlockParserSyntaxException($reason, '', $line);
            }
            if ($depth === 0) {
                yield [$token, $line, true];  // True because this is treated like a string
                yield [';', $line, false];

                break;
            }
            $token .= $char;
        }
    }

    public function build(array $stmt, string $padding, int $indent = 4, bool $tabs = false): string
    {
        $built = $stmt['directive'];
        if ($built === 'set_by_lua_block') {
            $block = $stmt['args'][1];
            $built .= sprintf(' %s', $stmt['args'][0]);
        } else {
            $block = $stmt['args'][0];
        }

        return $built . ' {' . $block . '}';
    }
}
