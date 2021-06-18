<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserException;

/**
 * @internal
 *
 * @small
 */
class FormatterTest extends AbstractTestCase
{
    /**
     * @dataProvider provideFormat
     *
     * @param string $configFile
     * @param string $expectedOutput
     *
     * @throws NgxParserException
     */
    public function testFormat(string $configFile, string $expectedOutput): void
    {
        $crossplane = new Crossplane();
        $actualOutput = $crossplane->formatter()->format($configFile);
        static::assertSame($expectedOutput, $actualOutput);
    }

    public function provideFormat(): \Generator
    {
        $config = <<<'CONF'
user nobody;
# hello\n\\n\\\n worlddd  \#\\#\\\# dfsf\n \\n \\\n 
events {
    worker_connections 2048;
}
http { #forteen
    # this is a comment
    access_log off;
    default_type text/plain;
    error_log off;
    server {
        listen 8083;
        return 200 'Ser" \' \' ver\\ \ $server_addr:\$server_port\n\nTime: $time_local\n\n';
    }
    server {
        listen 8080;
        root /usr/share/nginx/html;
        location ~ '/hello/world;' {
            return 301 /status.html;
        }
        location /foo {
        }
        location /bar {
        }
        location /\{\;\}\ #\ ab {
        } # hello
        if ($request_method = P\{O\)\###\;ST) {
        }
        location /status.html {
            try_files '/abc/${uri} /abc/${uri}.html' =404;
        }
        location '/sta;\n                    tus' {
            return 302 /status.html;
        }
        location /upstream_conf {
            return 200 /status.html;
        }
    }
    server {
    }
}
CONF;
        yield 'messy_config' => [
            __DIR__ . '/configs/messy/nginx.conf',
            $config,
        ];

        $config = <<<'CONF'
server {
    listen 8080;
    include locations/*.conf;
}
CONF;
        yield 'not_main_file' => [
            __DIR__ . '/configs/includes-globbed/servers/server1.conf',
            $config,
        ];

        $config = <<<'CONF'
user;
events {
}
http {
}
CONF;
        yield 'args_not_analyzed' => [
            __DIR__ . '/configs/bad-args/nginx.conf',
            $config,
        ];

        $config = <<<'CONF'
events {
    worker_connections 1024;
}
#comment
http {
    server {
        listen 127.0.0.1:8080; #listen
        server_name default_server;
        location / { ## this is brace
            # location /
            return 200 'foo bar baz';
        }
    }
}
CONF;
        yield 'with_comments' => [
            __DIR__ . '/configs/with-comments/nginx.conf',
            $config,
        ];
    }
}
