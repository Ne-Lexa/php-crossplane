<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Exception\NgxParserException;

class Formatter
{
    /** @var Parser */
    private $parser;

    /** @var Builder */
    private $builder;

    /**
     * @param Parser|null  $parser
     * @param Builder|null $builder
     */
    public function __construct(?Parser $parser = null, ?Builder $builder = null)
    {
        $this->parser = $parser ?? new Parser();
        $this->builder = $builder ?? new Builder();
    }

    /**
     * @param string $filename
     * @param int    $indent
     * @param bool   $tabs
     *
     * @throws NgxParserException
     *
     * @return string
     */
    public function format(string $filename, int $indent = 4, bool $tabs = false): string
    {
        $payload = $this->parser->parse(
            $filename,
            [
                Parser::OPTION_SINGLE_FILE => true,
                Parser::OPTION_COMMENTS => true,
                Parser::OPTION_CHECK_CTX => false,
                Parser::OPTION_CHECK_ARGS => false,
            ]
        );

        if ($payload['status'] !== 'ok') {
            $e = $payload['errors'][0];

            throw new NgxParserException($e['error'], $e['file'], $e['line']);
        }

        $parsed = $payload['config'][0]['parsed'];

        return $this->builder->build($parsed, $indent, $tabs);
    }
}
