<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserException;
use Nelexa\NginxParser\Util\StringUtil;
use PHPUnit\Framework\TestCase;

abstract class AbstractTestCase extends TestCase
{
    public static function assertEqualPayloads($a, $b, $ignoreKeys = []): void
    {
        static::assertSame(\gettype($a), \gettype($b));
        if (\is_array($a)) {
            if (!self::isAssoc($a)) {
                static::assertCount(\count($a), $b);

                foreach ($a as $k => $v) {
                    self::assertEqualPayloads($v, $b[$k], $ignoreKeys);
                }
            } else {
                $keys = array_keys($a);
                $keys = array_diff($keys, $ignoreKeys);
                foreach ($keys as $key) {
                    static::assertArrayHasKey($key, $b);
                    self::assertEqualPayloads($a[$key], $b[$key], $ignoreKeys);
                }
            }
        } elseif (\is_string($a)) {
            static::assertSame(StringUtil::enquote($a), StringUtil::enquote($b));
        } else {
            static::assertSame($a, $b);
        }
    }

    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }

    /**
     * @param Crossplane|null $crossplane
     * @param string          $configFilename
     * @param array           $parserOptions
     *
     * @throws NgxParserException
     */
    public static function compareParsedAndBuilt(?Crossplane $crossplane, string $configFilename, array $parserOptions = []): void
    {
        $crossplane = $crossplane ?? new Crossplane();
        $tmpDir = sys_get_temp_dir();

        $parser = $crossplane->parser();
        $builder = $crossplane->builder();

        $originalPayload = $parser->parse($configFilename, $parserOptions);
        $originalParsed = $originalPayload['config'][0]['parsed'];

        $build1File = $tmpDir . \DIRECTORY_SEPARATOR . 'build1.conf';
        $build2File = $tmpDir . \DIRECTORY_SEPARATOR . 'build2.conf';

        try {
            $build1Config = $builder->build($originalParsed);
            static::assertNotFalse(file_put_contents($build1File, $build1Config));
            $build1Payload = $parser->parse($build1File, $parserOptions);
            $build1Parsed = $build1Payload['config'][0]['parsed'];

            self::assertEqualPayloads($originalParsed, $build1Parsed, ['line']);

            $build2Config = $builder->build($build1Parsed);
            static::assertNotFalse(file_put_contents($build2File, $build2Config));
            $build2Payload = $parser->parse($build1File, $parserOptions);
            $build2Parsed = $build2Payload['config'][0]['parsed'];

            static::assertSame($build1Config, $build2Config);
            self::assertEqualPayloads($build1Parsed, $build2Parsed);
        } finally {
            if (is_file($build1File)) {
                unlink($build1File);
            }

            if (is_file($build2File)) {
                unlink($build2File);
            }
        }
    }
}
