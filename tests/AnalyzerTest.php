<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests;

use Nelexa\NginxParser\Analyzer;
use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserDirectiveArgumentsException;
use Nelexa\NginxParser\Exception\NgxParserDirectiveContextException;
use Nelexa\NginxParser\Exception\NgxParserException;

/**
 * @internal
 *
 * @small
 */
final class AnalyzerTest extends AbstractTestCase
{
    /**
     * @throws NgxParserException
     */
    public function testStateDirective(): void
    {
        $crossplane = new Crossplane();

        $fname = '/path/to/nginx.conf';

        $stmt = [
            'directive' => 'state',
            'args' => ['/path/to/state/file.conf'],
            'line' => 5,  // this is arbitrary
        ];

        // the state directive should not cause errors if it's in these contexts
        $goodContexts = [
            ['http', 'upstream'],
            ['stream', 'upstream'],
            ['some_third_party_context'],
        ];

        foreach ($goodContexts as $ctx) {
            $crossplane->analyzer()->analyze($fname, $stmt, ';', [
                Analyzer::OPTION_CTX => $ctx,
            ]);
        }

        $arraySub = static function (array $a, array $b): array {
            foreach ($a as $keyA => $valueA) {
                if (\in_array($valueA, $b, true)) {
                    unset($a[$keyA]);
                }
            }

            return $a;
        };

        // the state directive should not be in any of these contexts
        $badContexts = $arraySub($crossplane->analyzer()::CONTEXTS, $goodContexts);
        foreach ($badContexts as $ctx) {
            try {
                $crossplane->analyzer()->analyze($fname, $stmt, ';', [
                    Analyzer::OPTION_CTX => $ctx,
                ]);
                self::fail("bad context for 'state' passed: " . json_encode($ctx));
            } catch (NgxParserException $e) {
                self::assertInstanceOf(NgxParserDirectiveContextException::class, $e);
            }
        }
    }

    /**
     * @throws NgxParserException
     */
    public function testFlagDirectiveArgs(): void
    {
        $crossplane = new Crossplane();
        $fname = '/path/to/nginx.conf';
        $ctx = ['events'];

        // an NGINX_CONF_FLAG directive
        $stmt = [
            'directive' => 'accept_mutex',
            'line' => 2,  // this is arbitrary
        ];

        $goodArgs = [['on'], ['off'], ['On'], ['Off'], ['ON'], ['OFF']];

        foreach ($goodArgs as $args) {
            $stmt['args'] = $args;
            $crossplane->analyzer()->analyze($fname, $stmt, ';', [
                Analyzer::OPTION_CTX => $ctx,
            ]);
        }

        $badArgs = [['1'], ['0'], ['true'], ['okay'], ['']];

        foreach ($badArgs as $args) {
            $stmt['args'] = $args;

            try {
                $crossplane->analyzer()->analyze($fname, $stmt, ';', [
                    Analyzer::OPTION_CTX => $ctx,
                ]);
                self::fail('bad args for flag directive: ' . json_encode($ctx));
            } catch (NgxParserDirectiveArgumentsException $e) {
                self::assertStringEndsWith('it must be "on" or "off"', $e->getMessage());
            }
        }
    }
}
