<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserException;
use Nelexa\NginxParser\Parser;

/**
 * @internal
 *
 * @small
 */
class ParserTest extends AbstractTestCase
{
    /**
     * @dataProvider provideParse
     *
     * @param string $filename
     * @param array  $expectedPayload
     * @param array  $parserOptions
     *
     * @throws NgxParserException
     */
    public function testParse(string $filename, array $expectedPayload, array $parserOptions): void
    {
        $crossplane = new Crossplane();
        $payload = $crossplane->parser()->parse($filename, $parserOptions);
        static::assertEquals($expectedPayload, $payload);
    }

    public function provideParse(): \Generator
    {
        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'includes-regular';
        yield 'includes_regular' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'failed',
                'errors' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'conf.d' . \DIRECTORY_SEPARATOR . 'server.conf',
                        'error' => sprintf("No such file or directory: '%s%sbar.conf' in %s%snginx.conf:5", $dir, \DIRECTORY_SEPARATOR, $dir, \DIRECTORY_SEPARATOR),
                        'line' => 5,
                    ],
                ],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'events',
                                'line' => 1,
                                'args' => [],
                                'block' => [],
                            ],
                            [
                                'directive' => 'http',
                                'line' => 2,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'include',
                                        'line' => 3,
                                        'args' => ['conf.d/server.conf'],
                                        'includes' => [1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'conf.d' . \DIRECTORY_SEPARATOR . 'server.conf',
                        'status' => 'failed',
                        'errors' => [
                            [
                                'error' => sprintf(
                                    "No such file or directory: '%s' in %s:5",
                                    $dir . \DIRECTORY_SEPARATOR . 'bar.conf',
                                    $dir . \DIRECTORY_SEPARATOR . 'nginx.conf'
                                ),
                                'line' => 5,
                            ],
                        ],
                        'parsed' => [
                            [
                                'directive' => 'server',
                                'line' => 1,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'listen',
                                        'line' => 2,
                                        'args' => ['127.0.0.1:8080'],
                                    ],
                                    [
                                        'directive' => 'server_name',
                                        'line' => 3,
                                        'args' => ['default_server'],
                                    ],
                                    [
                                        'directive' => 'include',
                                        'line' => 4,
                                        'args' => ['foo.conf'],
                                        'includes' => [2],
                                    ],
                                    [
                                        'directive' => 'include',
                                        'line' => 5,
                                        'args' => ['bar.conf'],
                                        'includes' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'foo.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'location',
                                'line' => 1,
                                'args' => ['/foo'],
                                'block' => [
                                    [
                                        'directive' => 'return',
                                        'line' => 2,
                                        'args' => ['200', 'foo'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [], // args
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'includes-globbed';
        yield 'includes_globbed' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'events',
                                'line' => 1,
                                'args' => [],
                                'block' => [],
                            ],
                            [
                                'directive' => 'include',
                                'line' => 2,
                                'args' => ['http.conf'],
                                'includes' => [1],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'http.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'http',
                                'args' => [],
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'include',
                                        'line' => 2,
                                        'args' => ['servers/*.conf'],
                                        'includes' => [2, 3],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server1.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'server',
                                'args' => [],
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'listen',
                                        'args' => ['8080'],
                                        'line' => 2,
                                    ],
                                    [
                                        'directive' => 'include',
                                        'args' => ['locations/*.conf'],
                                        'line' => 3,
                                        'includes' => [4, 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server2.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'server',
                                'args' => [],
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'listen',
                                        'args' => ['8081'],
                                        'line' => 2,
                                    ],
                                    [
                                        'directive' => 'include',
                                        'args' => ['locations/*.conf'],
                                        'line' => 3,
                                        'includes' => [4, 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location1.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'location',
                                'args' => ['/foo'],
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'return',
                                        'args' => ['200', 'foo'],
                                        'line' => 2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location2.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'location',
                                'args' => ['/bar'],
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'return',
                                        'args' => ['200', 'bar'],
                                        'line' => 2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [], // args
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'includes-globbed';
        yield 'globbed_combined' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'events',
                                'args' => [],
                                'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                                'line' => 1,
                                'block' => [],
                            ],
                            [
                                'directive' => 'http',
                                'args' => [],
                                'file' => $dir . \DIRECTORY_SEPARATOR . 'http.conf',
                                'line' => 1,
                                'block' => [
                                    [
                                        'directive' => 'server',
                                        'args' => [],
                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server1.conf',
                                        'line' => 1,
                                        'block' => [
                                            [
                                                'directive' => 'listen',
                                                'args' => ['8080'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server1.conf',
                                                'line' => 2,
                                            ],
                                            [
                                                'directive' => 'location',
                                                'args' => ['/foo'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location1.conf',
                                                'line' => 1,
                                                'block' => [
                                                    [
                                                        'directive' => 'return',
                                                        'args' => ['200', 'foo'],
                                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location1.conf',
                                                        'line' => 2,
                                                    ],
                                                ],
                                            ],
                                            [
                                                'directive' => 'location',
                                                'args' => ['/bar'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location2.conf',
                                                'line' => 1,
                                                'block' => [
                                                    [
                                                        'directive' => 'return',
                                                        'args' => ['200', 'bar'],
                                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location2.conf',
                                                        'line' => 2,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    [
                                        'directive' => 'server',
                                        'args' => [],
                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server2.conf',
                                        'line' => 1,
                                        'block' => [
                                            [
                                                'directive' => 'listen',
                                                'args' => ['8081'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'servers' . \DIRECTORY_SEPARATOR . 'server2.conf',
                                                'line' => 2,
                                            ],
                                            [
                                                'directive' => 'location',
                                                'args' => ['/foo'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location1.conf',
                                                'line' => 1,
                                                'block' => [
                                                    [
                                                        'directive' => 'return',
                                                        'args' => ['200', 'foo'],
                                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location1.conf',
                                                        'line' => 2,
                                                    ],
                                                ],
                                            ],
                                            [
                                                'directive' => 'location',
                                                'args' => ['/bar'],
                                                'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location2.conf',
                                                'line' => 1,
                                                'block' => [
                                                    [
                                                        'directive' => 'return',
                                                        'args' => ['200', 'bar'],
                                                        'file' => $dir . \DIRECTORY_SEPARATOR . 'locations' . \DIRECTORY_SEPARATOR . 'location2.conf',
                                                        'line' => 2,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                Parser::OPTION_COMBINE => true,
            ], // args
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'includes-regular';
        yield 'includes_single' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'events',
                                'line' => 1,
                                'args' => [],
                                'block' => [],
                            ],
                            [
                                'directive' => 'http',
                                'line' => 2,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'include',
                                        'line' => 3,
                                        'args' => ['conf.d/server.conf'],
                                        // no 'includes' key
                                    ],
                                ],
                            ],
                        ],
                    ],
                    // single config parsed
                ],
            ],
            [
                Parser::OPTION_SINGLE_FILE => true,
            ],
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'simple';
        yield 'ignore_directives_listen__server_names' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
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
                                                'directive' => 'location',
                                                'line' => 9,
                                                'args' => ['/'],
                                                'block' => [
                                                    [
                                                        'directive' => 'return',
                                                        'line' => 10,
                                                        'args' => ['200', 'foo bar baz'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                Parser::OPTION_IGNORE => ['listen', 'server_name'],
            ],
        ];

        yield 'ignore_directives_events__server' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'http',
                                'line' => 5,
                                'args' => [],
                                'block' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                Parser::OPTION_IGNORE => ['events', 'server'],
            ],
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'with-comments';
        yield 'with_comments' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'errors' => [],
                'status' => 'ok',
                'config' => [
                    [
                        'errors' => [],
                        'parsed' => [
                            [
                                'block' => [
                                    [
                                        'directive' => 'worker_connections',
                                        'args' => [
                                            '1024',
                                        ],
                                        'line' => 2,
                                    ],
                                ],
                                'line' => 1,
                                'args' => [],
                                'directive' => 'events',
                            ],
                            [
                                'line' => 4,
                                'directive' => '#',
                                'args' => [],
                                'comment' => 'comment',
                            ],
                            [
                                'block' => [
                                    [
                                        'args' => [],
                                        'directive' => 'server',
                                        'line' => 6,
                                        'block' => [
                                            [
                                                'args' => [
                                                    '127.0.0.1:8080',
                                                ],
                                                'directive' => 'listen',
                                                'line' => 7,
                                            ],
                                            [
                                                'args' => [],
                                                'directive' => '#',
                                                'comment' => 'listen',
                                                'line' => 7,
                                            ],
                                            [
                                                'args' => [
                                                    'default_server',
                                                ],
                                                'directive' => 'server_name',
                                                'line' => 8,
                                            ],
                                            [
                                                'block' => [
                                                    [
                                                        'args' => [],
                                                        'directive' => '#',
                                                        'line' => 9,
                                                        'comment' => '# this is brace',
                                                    ],
                                                    [
                                                        'args' => [],
                                                        'directive' => '#',
                                                        'line' => 10,
                                                        'comment' => ' location /',
                                                    ],
                                                    [
                                                        'line' => 11,
                                                        'directive' => 'return',
                                                        'args' => [
                                                            '200',
                                                            'foo bar baz',
                                                        ],
                                                    ],
                                                ],
                                                'line' => 9,
                                                'directive' => 'location',
                                                'args' => [
                                                    '/',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'line' => 5,
                                'args' => [],
                                'directive' => 'http',
                            ],
                        ],
                        'status' => 'ok',
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                    ],
                ],
            ],
            [
                Parser::OPTION_COMMENTS => true,
            ],
        ];

        yield 'without_comments' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'errors' => [],
                'status' => 'ok',
                'config' => [
                    [
                        'errors' => [],
                        'parsed' => [
                            [
                                'block' => [
                                    [
                                        'directive' => 'worker_connections',
                                        'args' => [
                                            '1024',
                                        ],
                                        'line' => 2,
                                    ],
                                ],
                                'line' => 1,
                                'args' => [],
                                'directive' => 'events',
                            ],
                            [
                                'block' => [
                                    [
                                        'args' => [],
                                        'directive' => 'server',
                                        'line' => 6,
                                        'block' => [
                                            [
                                                'args' => [
                                                    '127.0.0.1:8080',
                                                ],
                                                'directive' => 'listen',
                                                'line' => 7,
                                            ],
                                            [
                                                'args' => [
                                                    'default_server',
                                                ],
                                                'directive' => 'server_name',
                                                'line' => 8,
                                            ],
                                            [
                                                'block' => [
                                                    [
                                                        'line' => 11,
                                                        'directive' => 'return',
                                                        'args' => [
                                                            '200',
                                                            'foo bar baz',
                                                        ],
                                                    ],
                                                ],
                                                'line' => 9,
                                                'directive' => 'location',
                                                'args' => [
                                                    '/',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'line' => 5,
                                'args' => [],
                                'directive' => 'http',
                            ],
                        ],
                        'status' => 'ok',
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                    ],
                ],
            ],
            [
                Parser::OPTION_COMMENTS => false,
            ],
        ];

        $dir = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'spelling-mistake';
        yield 'strict' => [
            $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
            [
                'status' => 'failed',
                'errors' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'error' => sprintf('unknown directive "proxy_passs" in %s:7', $dir . \DIRECTORY_SEPARATOR . 'nginx.conf'),
                        'line' => 7,
                    ],
                ],
                'config' => [
                    [
                        'file' => $dir . \DIRECTORY_SEPARATOR . 'nginx.conf',
                        'status' => 'failed',
                        'errors' => [
                            [
                                'error' => sprintf('unknown directive "proxy_passs" in %s:7', $dir . \DIRECTORY_SEPARATOR . 'nginx.conf'),
                                'line' => 7,
                            ],
                        ],
                        'parsed' => [
                            [
                                'directive' => 'events',
                                'line' => 1,
                                'args' => [],
                                'block' => [],
                            ],
                            [
                                'directive' => 'http',
                                'line' => 3,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'server',
                                        'line' => 4,
                                        'args' => [],
                                        'block' => [
                                            [
                                                'directive' => 'location',
                                                'line' => 5,
                                                'args' => ['/'],
                                                'block' => [
                                                    [
                                                        'directive' => '#',
                                                        'line' => 6,
                                                        'args' => [],
                                                        'comment' => 'directive is misspelled',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                Parser::OPTION_COMMENTS => true,
                Parser::OPTION_STRICT => true,
            ],
        ];

        $aboveConfig = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'missing-semicolon' . \DIRECTORY_SEPARATOR . 'broken-above.conf';
        yield 'missing_semicolon_above' => [
            $aboveConfig,
            [
                'status' => 'failed',
                'errors' => [
                    [
                        'file' => $aboveConfig,
                        'error' => sprintf('directive "proxy_pass" is not terminated by ";" in %s:4', $aboveConfig),
                        'line' => 4,
                    ],
                ],
                'config' => [
                    [
                        'file' => $aboveConfig,
                        'status' => 'failed',
                        'errors' => [
                            [
                                'error' => sprintf('directive "proxy_pass" is not terminated by ";" in %s:4', $aboveConfig),
                                'line' => 4,
                            ],
                        ],
                        'parsed' => [
                            [
                                'directive' => 'http',
                                'line' => 1,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'server',
                                        'line' => 2,
                                        'args' => [],
                                        'block' => [
                                            [
                                                'directive' => 'location',
                                                'line' => 3,
                                                'args' => ['/is-broken'],
                                                'block' => [],
                                            ],
                                            [
                                                'directive' => 'location',
                                                'line' => 6,
                                                'args' => ['/not-broken'],
                                                'block' => [
                                                    [
                                                        'directive' => 'proxy_pass',
                                                        'line' => 7,
                                                        'args' => ['http://not.broken.example'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [],
        ];

        $belowConfig = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'missing-semicolon' . \DIRECTORY_SEPARATOR . 'broken-below.conf';
        yield 'missing_semicolon_below' => [
            $belowConfig,
            [
                'status' => 'failed',
                'errors' => [
                    [
                        'file' => $belowConfig,
                        'error' => sprintf('directive "proxy_pass" is not terminated by ";" in %s:7', $belowConfig),
                        'line' => 7,
                    ],
                ],
                'config' => [
                    [
                        'file' => $belowConfig,
                        'status' => 'failed',
                        'errors' => [
                            [
                                'error' => sprintf('directive "proxy_pass" is not terminated by ";" in %s:7', $belowConfig),
                                'line' => 7,
                            ],
                        ],
                        'parsed' => [
                            [
                                'directive' => 'http',
                                'line' => 1,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => 'server',
                                        'line' => 2,
                                        'args' => [],
                                        'block' => [
                                            [
                                                'directive' => 'location',
                                                'line' => 3,
                                                'args' => ['/not-broken'],
                                                'block' => [
                                                    [
                                                        'directive' => 'proxy_pass',
                                                        'line' => 4,
                                                        'args' => ['http://not.broken.example'],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'directive' => 'location',
                                                'line' => 6,
                                                'args' => ['/is-broken'],
                                                'block' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [],
        ];

        $configFile = __DIR__ . \DIRECTORY_SEPARATOR . 'configs' . \DIRECTORY_SEPARATOR . 'comments-between-args' . \DIRECTORY_SEPARATOR . 'nginx.conf';
        yield 'comments_between_args' => [
            $configFile,
            [
                'status' => 'ok',
                'errors' => [],
                'config' => [
                    [
                        'file' => $configFile,
                        'status' => 'ok',
                        'errors' => [],
                        'parsed' => [
                            [
                                'directive' => 'http',
                                'line' => 1,
                                'args' => [],
                                'block' => [
                                    [
                                        'directive' => '#',
                                        'line' => 1,
                                        'args' => [],
                                        'comment' => 'comment 1',
                                    ],
                                    [
                                        'directive' => 'log_format',
                                        'line' => 2,
                                        'args' => ['\\#arg\\ 1', '#arg 2'],
                                    ],
                                    [
                                        'directive' => '#',
                                        'line' => 2,
                                        'args' => [],
                                        'comment' => 'comment 2',
                                    ],
                                    [
                                        'directive' => '#',
                                        'line' => 2,
                                        'args' => [],
                                        'comment' => 'comment 3',
                                    ],
                                    [
                                        'directive' => '#',
                                        'line' => 2,
                                        'args' => [],
                                        'comment' => 'comment 4',
                                    ],
                                    [
                                        'directive' => '#',
                                        'line' => 2,
                                        'args' => [],
                                        'comment' => 'comment 5',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                Parser::OPTION_COMMENTS => true,
            ],
        ];
    }

    /**
     * @throws \ReflectionException
     */
    public function testCombineParsedMissingValues(): void
    {
        $separate = [
            'config' => [
                [
                    'file' => 'example1.conf',
                    'parsed' => [
                        [
                            'directive' => 'include',
                            'line' => 1,
                            'args' => ['example2.conf'],
                            'includes' => [1],
                        ],
                    ],
                ],
                [
                    'file' => 'example2.conf',
                    'parsed' => [
                        [
                            'directive' => 'events',
                            'line' => 1,
                            'args' => [],
                            'block' => [],
                        ],
                        [
                            'directive' => 'http',
                            'line' => 2,
                            'args' => [],
                            'block' => [],
                        ],
                    ],
                ],
            ],
        ];

        $crossplane = new Crossplane();
        $parser = $crossplane->parser();
        $reflClass = new \ReflectionClass($parser);
        $reflMethod = $reflClass->getMethod('combineParsedConfigs');
        $reflMethod->setAccessible(true);
        $combinedActual = $reflMethod->invoke($parser, $separate);

        $combinedException = [
            'status' => 'ok',
            'errors' => [],
            'config' => [
                [
                    'file' => 'example1.conf',
                    'status' => 'ok',
                    'errors' => [],
                    'parsed' => [
                        [
                            'directive' => 'events',
                            'line' => 1,
                            'args' => [],
                            'block' => [],
                        ],
                        [
                            'directive' => 'http',
                            'line' => 2,
                            'args' => [],
                            'block' => [],
                        ],
                    ],
                ],
            ],
        ];
        static::assertSame($combinedException, $combinedActual);
    }
}
