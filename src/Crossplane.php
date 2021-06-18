<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Ext\CrossplaneExtension;
use Nelexa\NginxParser\Ext\LuaBlockPlugin;

/**
 * Reliable and fast NGINX configuration file parser.
 */
class Crossplane
{
    public const VERSION = '1.0.1';

    private const DEFAULT_ENABLED_EXTENSION = [LuaBlockPlugin::class];

    /** @var Parser|null */
    private $parser;

    /** @var Lexer|null */
    private $lexer;

    /** @var Builder|null */
    private $builder;

    /** @var Formatter|null */
    private $formatter;

    /** @var Analyzer|null */
    private $analyzer;

    public function __construct()
    {
        foreach (self::DEFAULT_ENABLED_EXTENSION as $extensionClass) {
            $this->registerExtension(new $extensionClass());
        }
    }

    public function parser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = new Parser($this->lexer(), $this->analyzer());
        }

        return $this->parser;
    }

    public function lexer(): Lexer
    {
        if ($this->lexer === null) {
            $this->lexer = new Lexer();
        }

        return $this->lexer;
    }

    public function builder(): Builder
    {
        if ($this->builder === null) {
            $this->builder = new Builder();
        }

        return $this->builder;
    }

    public function analyzer(): Analyzer
    {
        if ($this->analyzer === null) {
            $this->analyzer = new Analyzer();
        }

        return $this->analyzer;
    }

    public function formatter(): Formatter
    {
        if ($this->formatter === null) {
            $this->formatter = new Formatter($this->parser(), $this->builder());
        }

        return $this->formatter;
    }

    public function registerExtension(CrossplaneExtension $extension): void
    {
        $extension->registerExtension($this);
    }
}
