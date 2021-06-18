<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserIOException;

/**
 * @internal
 *
 * @small
 */
class BuilderTest extends AbstractTestCase
{
    /**
     * @dataProvider provideBuild
     *
     * @param array  $payload
     * @param string $expectedBuilt
     */
    public function testBuild(array $payload, string $expectedBuilt): void
    {
        $crossplane = new Crossplane();
        $actualBuilt = $crossplane->builder()->build($payload, 4, false);
        static::assertSame($expectedBuilt, $actualBuilt);
    }

    public function provideBuild(): \Generator
    {
        yield 'nested_and_multiple_args' => [
            [
                [
                    'directive' => 'events',
                    'args' => [],
                    'block' => [
                        [
                            'directive' => 'worker_connections',
                            'args' => [
                                '1024',
                            ],
                        ],
                    ],
                ],
                [
                    'directive' => 'http',
                    'args' => [
                    ],
                    'block' => [
                        [
                            'directive' => 'server',
                            'args' => [
                            ],
                            'block' => [
                                [
                                    'directive' => 'listen',
                                    'args' => [
                                        '127.0.0.1:8080',
                                    ],
                                ],
                                [
                                    'directive' => 'server_name',
                                    'args' => [
                                        'default_server',
                                    ],
                                ],
                                [
                                    'directive' => 'location',
                                    'args' => [
                                        '/',
                                    ],
                                    'block' => [
                                        [
                                            'directive' => 'return',
                                            'args' => [
                                                '200',
                                                'foo bar baz',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "events {
    worker_connections 1024;
}
http {
    server {
        listen 127.0.0.1:8080;
        server_name default_server;
        location / {
            return 200 'foo bar baz';
        }
    }
}",
        ];

        yield 'with_comments' => [
            [
                [
                    'directive' => 'events',
                    'line' => 1,
                    'args' => [],
                    'block' => [
                        [
                            'directive' => 'worker_connections',
                            'line' => 2,
                            'args' => ['1024'],
                        ],
                    ],
                ],
                [
                    'directive' => '#',
                    'line' => 4,
                    'args' => [],
                    'comment' => 'comment',
                ],
                [
                    'directive' => 'http',
                    'line' => 5,
                    'args' => [],
                    'block' => [
                        [
                            'directive' => 'server',
                            'line' => 6,
                            'args' => [],
                            'block' => [
                                [
                                    'directive' => 'listen',
                                    'line' => 7,
                                    'args' => ['127.0.0.1:8080'],
                                ],
                                [
                                    'directive' => '#',
                                    'line' => 7,
                                    'args' => [],
                                    'comment' => 'listen',
                                ],
                                [
                                    'directive' => 'server_name',
                                    'line' => 8,
                                    'args' => ['default_server'],
                                ],
                                [
                                    'directive' => 'location',
                                    'line' => 9,
                                    'args' => ['/'],
                                    'block' => [
                                        [
                                            'directive' => '#',
                                            'line' => 9,
                                            'args' => [],
                                            'comment' => '# this is brace',
                                        ],
                                        [
                                            'directive' => '#',
                                            'line' => 10,
                                            'args' => [],
                                            'comment' => ' location /',
                                        ],
                                        [
                                            'directive' => '#',
                                            'line' => 11,
                                            'args' => [],
                                            'comment' => ' is here',
                                        ],
                                        [
                                            'directive' => 'return',
                                            'line' => 12,
                                            'args' => ['200', 'foo bar baz'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "events {
    worker_connections 1024;
}
#comment
http {
    server {
        listen 127.0.0.1:8080; #listen
        server_name default_server;
        location / { ## this is brace
            # location /
            # is here
            return 200 'foo bar baz';
        }
    }
}",
        ];

        yield 'starts_with_comments' => [
            [
                [
                    'directive' => '#',
                    'line' => 1,
                    'args' => [],
                    'comment' => ' foo',
                ],
                [
                    'directive' => 'user',
                    'line' => 5,
                    'args' => ['root'],
                ],
            ],
            "# foo\nuser root;",
        ];

        yield 'with_quoted_unicode' => [
            [
                [
                    'directive' => 'env',
                    'line' => 1,
                    'args' => ['русский текст'],
                ],
            ],
            "env 'русский текст';",
        ];

        yield 'multiple_comments_on_one_line' => [
            [
                [
                    'directive' => '#',
                    'line' => 1,
                    'args' => [
                    ],
                    'comment' => 'comment1',
                ],
                [
                    'directive' => 'user',
                    'line' => 2,
                    'args' => [
                        'root',
                    ],
                ],
                [
                    'directive' => '#',
                    'line' => 2,
                    'args' => [
                    ],
                    'comment' => 'comment2',
                ],
                [
                    'directive' => '#',
                    'line' => 2,
                    'args' => [
                    ],
                    'comment' => 'comment3',
                ],
            ],
            "#comment1\nuser root; #comment2 #comment3",
        ];
    }

    /**
     * @dataProvider provideBuildFiles
     *
     * @param array  $payload
     * @param string $built
     *
     * @throws NgxParserIOException
     */
    public function testBuildFiles(array $payload, string $built): void
    {
        $tempDir = sys_get_temp_dir() . '/.crossplane/' . uniqid('test', false);
        static::assertTrue(mkdir($tempDir, 0755, true));

        $crossplane = new Crossplane();

        try {
            $crossplane->builder()->buildFiles($payload, $tempDir);
            $files = array_values(array_diff(scandir($tempDir), ['.', '..']));
            static::assertCount(1, $files);
            static::assertSame($files[0], 'nginx.conf');
            static::assertStringEqualsFile($tempDir . '/' . $files[0], $built);
        } finally {
            if (!is_file($tempDir . '/nginx.conf')) {
                unlink($tempDir . '/nginx.conf');
            }
            @rmdir($tempDir);
        }
    }

    public function provideBuildFiles(): \Generator
    {
        yield 'with_missing_status_and_errors' => [
            [
                'config' => [
                    [
                        'file' => 'nginx.conf',
                        'parsed' => [
                            [
                                'directive' => 'user',
                                'line' => 1,
                                'args' => ['nginx'],
                            ],
                        ],
                    ],
                ],
            ],
            "user nginx;\n",
        ];

        yield 'files_with_unicode' => [
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'user',
                                'line' => 1,
                                'args' => ['測試'],
                            ],
                        ],
                    ],
                ],
            ],
            "user 測試;\n",
        ];
    }

    /**
     * @dataProvider provideCompareParsedAndBuilt
     *
     * @param string $file
     *
     * @throws \Nelexa\NginxParser\Exception\NgxParserException
     */
    public function testCompareParsedAndBuilt(string $file): void
    {
        self::compareParsedAndBuilt(null, $file);
    }

    public function provideCompareParsedAndBuilt(): \Generator
    {
        yield 'simple' => [__DIR__ . '/configs/simple/nginx.conf'];
        yield 'messy' => [__DIR__ . '/configs/messy/nginx.conf'];
        yield 'messy_with_comments' => [__DIR__ . '/configs/with-comments/nginx.conf'];
        yield 'empty_map_values' => [__DIR__ . '/configs/empty-value-map/nginx.conf'];
        yield 'russian_text' => [__DIR__ . '/configs/russian-text/nginx.conf'];
        yield 'quoted_right_brace' => [__DIR__ . '/configs/quoted-right-brace/nginx.conf'];
        yield 'directive_with_space' => [__DIR__ . '/configs/directive-with-space/nginx.conf'];
    }
}
