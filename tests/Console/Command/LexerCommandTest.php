<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests\Console\Command;

use Nelexa\NginxParser\Console\Command\LexCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @small
 */
final class LexerCommandTest extends TestCase
{
    /**
     * @dataProvider provideCommandArguments
     *
     * @param array  $arguments
     * @param string $display
     */
    public function testLexerCommand(array $arguments, string $display): void
    {
        $command = new LexCommand();
        $tester = new CommandTester($command);
        $tester->execute($arguments);
        self::assertSame($display, $tester->getDisplay());
    }

    public function provideCommandArguments(): \Generator
    {
        yield 'only_filename' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
            ],
            '[["events"],["{"],["worker_connections"],["1024"],[";"],["}"],["http"],["{"],["server"],["{"],["listen"],["127.0.0.1:8080"],[";"],["server_name"],["default_server"],[";"],["location"],["/"],["{"],["return"],["200"],["foo bar baz"],[";"],["}"],["}"],["}"]]
',
        ];

        yield 'with_indents_1' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--indent' => 1,
            ],
            '[
 [
  "events"
 ],
 [
  "{"
 ],
 [
  "worker_connections"
 ],
 [
  "1024"
 ],
 [
  ";"
 ],
 [
  "}"
 ],
 [
  "http"
 ],
 [
  "{"
 ],
 [
  "server"
 ],
 [
  "{"
 ],
 [
  "listen"
 ],
 [
  "127.0.0.1:8080"
 ],
 [
  ";"
 ],
 [
  "server_name"
 ],
 [
  "default_server"
 ],
 [
  ";"
 ],
 [
  "location"
 ],
 [
  "/"
 ],
 [
  "{"
 ],
 [
  "return"
 ],
 [
  "200"
 ],
 [
  "foo bar baz"
 ],
 [
  ";"
 ],
 [
  "}"
 ],
 [
  "}"
 ],
 [
  "}"
 ]
]
',
        ];
        yield 'with_indents_2' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--indent' => 2,
            ],
            '[
  [
    "events"
  ],
  [
    "{"
  ],
  [
    "worker_connections"
  ],
  [
    "1024"
  ],
  [
    ";"
  ],
  [
    "}"
  ],
  [
    "http"
  ],
  [
    "{"
  ],
  [
    "server"
  ],
  [
    "{"
  ],
  [
    "listen"
  ],
  [
    "127.0.0.1:8080"
  ],
  [
    ";"
  ],
  [
    "server_name"
  ],
  [
    "default_server"
  ],
  [
    ";"
  ],
  [
    "location"
  ],
  [
    "/"
  ],
  [
    "{"
  ],
  [
    "return"
  ],
  [
    "200"
  ],
  [
    "foo bar baz"
  ],
  [
    ";"
  ],
  [
    "}"
  ],
  [
    "}"
  ],
  [
    "}"
  ]
]
',
        ];
        yield 'with_indents_3' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--indent' => 3,
            ],
            '[
   [
      "events"
   ],
   [
      "{"
   ],
   [
      "worker_connections"
   ],
   [
      "1024"
   ],
   [
      ";"
   ],
   [
      "}"
   ],
   [
      "http"
   ],
   [
      "{"
   ],
   [
      "server"
   ],
   [
      "{"
   ],
   [
      "listen"
   ],
   [
      "127.0.0.1:8080"
   ],
   [
      ";"
   ],
   [
      "server_name"
   ],
   [
      "default_server"
   ],
   [
      ";"
   ],
   [
      "location"
   ],
   [
      "/"
   ],
   [
      "{"
   ],
   [
      "return"
   ],
   [
      "200"
   ],
   [
      "foo bar baz"
   ],
   [
      ";"
   ],
   [
      "}"
   ],
   [
      "}"
   ],
   [
      "}"
   ]
]
',
        ];
        yield 'with_indents_4' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--indent' => 4,
            ],
            '[
    [
        "events"
    ],
    [
        "{"
    ],
    [
        "worker_connections"
    ],
    [
        "1024"
    ],
    [
        ";"
    ],
    [
        "}"
    ],
    [
        "http"
    ],
    [
        "{"
    ],
    [
        "server"
    ],
    [
        "{"
    ],
    [
        "listen"
    ],
    [
        "127.0.0.1:8080"
    ],
    [
        ";"
    ],
    [
        "server_name"
    ],
    [
        "default_server"
    ],
    [
        ";"
    ],
    [
        "location"
    ],
    [
        "/"
    ],
    [
        "{"
    ],
    [
        "return"
    ],
    [
        "200"
    ],
    [
        "foo bar baz"
    ],
    [
        ";"
    ],
    [
        "}"
    ],
    [
        "}"
    ],
    [
        "}"
    ]
]
',
        ];

        yield 'with_line_numbers' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--line-numbers' => true,
            ],
            '[["events",1],["{",1],["worker_connections",2],["1024",2],[";",2],["}",3],["http",5],["{",5],["server",6],["{",6],["listen",7],["127.0.0.1:8080",7],[";",7],["server_name",8],["default_server",8],[";",8],["location",9],["/",9],["{",9],["return",10],["200",10],["foo bar baz",10],[";",10],["}",11],["}",12],["}",13]]
',
        ];

        yield 'with_line_numbers_and_indent' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--line-numbers' => true,
                '--indent' => 2,
            ],
            '[
  [
    "events",
    1
  ],
  [
    "{",
    1
  ],
  [
    "worker_connections",
    2
  ],
  [
    "1024",
    2
  ],
  [
    ";",
    2
  ],
  [
    "}",
    3
  ],
  [
    "http",
    5
  ],
  [
    "{",
    5
  ],
  [
    "server",
    6
  ],
  [
    "{",
    6
  ],
  [
    "listen",
    7
  ],
  [
    "127.0.0.1:8080",
    7
  ],
  [
    ";",
    7
  ],
  [
    "server_name",
    8
  ],
  [
    "default_server",
    8
  ],
  [
    ";",
    8
  ],
  [
    "location",
    9
  ],
  [
    "/",
    9
  ],
  [
    "{",
    9
  ],
  [
    "return",
    10
  ],
  [
    "200",
    10
  ],
  [
    "foo bar baz",
    10
  ],
  [
    ";",
    10
  ],
  [
    "}",
    11
  ],
  [
    "}",
    12
  ],
  [
    "}",
    13
  ]
]
',
        ];
    }

    public function testWithSaveOutputToFile(): void
    {
        $expectedOutput = '[
  [
    "events",
    1
  ],
  [
    "{",
    1
  ],
  [
    "worker_connections",
    2
  ],
  [
    "1024",
    2
  ],
  [
    ";",
    2
  ],
  [
    "}",
    3
  ],
  [
    "http",
    5
  ],
  [
    "{",
    5
  ],
  [
    "server",
    6
  ],
  [
    "{",
    6
  ],
  [
    "listen",
    7
  ],
  [
    "127.0.0.1:8080",
    7
  ],
  [
    ";",
    7
  ],
  [
    "server_name",
    8
  ],
  [
    "default_server",
    8
  ],
  [
    ";",
    8
  ],
  [
    "location",
    9
  ],
  [
    "/",
    9
  ],
  [
    "{",
    9
  ],
  [
    "return",
    10
  ],
  [
    "200",
    10
  ],
  [
    "foo bar baz",
    10
  ],
  [
    ";",
    10
  ],
  [
    "}",
    11
  ],
  [
    "}",
    12
  ],
  [
    "}",
    13
  ]
]
';

        $tempFile = sys_get_temp_dir() . '/' . uniqid('phptest', false) . '.json';

        try {
            $command = new LexCommand();
            $tester = new CommandTester($command);
            $tester->execute([
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
                '--line-numbers' => true,
                '--indent' => 2,
                '--out' => $tempFile,
            ]);
            self::assertStringEqualsFile($tempFile, $expectedOutput);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
