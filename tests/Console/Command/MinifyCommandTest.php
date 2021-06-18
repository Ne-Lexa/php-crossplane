<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests\Console\Command;

use Nelexa\NginxParser\Console\Command\MinifyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @small
 */
class MinifyCommandTest extends TestCase
{
    /**
     * @dataProvider provideMinifyConfig
     *
     * @param array  $arguments
     * @param string $display
     */
    public function testMinifyConfig(array $arguments, string $display): void
    {
        $command = new MinifyCommand();
        $commandTester = new CommandTester($command);
        static::assertSame(0, $commandTester->execute($arguments));
        if (isset($arguments['--out'])) {
            try {
                static::assertStringEqualsFile($arguments['--out'], $display);
            } finally {
                if (is_file($arguments['--out'])) {
                    unlink($arguments['--out']);
                }
            }
        } else {
            static::assertSame($display, $commandTester->getDisplay());
        }
    }

    public function provideMinifyConfig(): \Generator
    {
        yield 'single' => [
            [
                'filename' => __DIR__ . '/../../configs/simple/nginx.conf',
            ],
            'events {worker_connections 1024;}http {server {listen 127.0.0.1:8080;server_name default_server;location /{return 200 \'foo bar baz\';}}}' . "\n",
        ];

        yield 'messy' => [
            [
                'filename' => __DIR__ . '/../../configs/messy/nginx.conf',
            ],
            'user nobody;events {worker_connections 2048;}http {access_log off;default_type text/plain;error_log off;server {listen 8083;return 200 \'Ser" \\\' \\\' ver\\\\ \\ $server_addr:\$server_port\n\nTime: $time_local\n\n\';}server {listen 8080;root /usr/share/nginx/html;location ~ \'/hello/world;\'{return 301 /status.html;}location /foo{}location /bar{}location /\{\;\}\ #\ ab{}if ($request_method = P\{O\)\###\;ST){}location /status.html{try_files \'/abc/${uri} /abc/${uri}.html\' =404;}location \'/sta;\n                    tus\'{return 302 /status.html;}location /upstream_conf{return 200 /status.html;}}server {}}' . "\n",
        ];

        yield 'lua' => [
            [
                'filename' => __DIR__ . '/../../configs/lua-block-tricky/nginx.conf',
                '--out' => sys_get_temp_dir() . '/' . uniqid('phptest', true) . '.conf',
            ],
            'http {server {listen 127.0.0.1:8080;server_name content_by_lua_block;set_by_lua_block $res \' -- irregular lua block directive\n            local a = 32\n            local b = 56\n\n            ngx.var.diff = a - b;  -- write to $diff directly\n            return a + b;          -- return the $sum value normally\n        \';rewrite_by_lua_block \' -- have valid braces in Lua code and quotes around directive\n            do_something("hello, world!\nhiya\n")\n            a = { 1, 2, 3 }\n            btn = iup.button({title="ok"})\n        \';}upstream content_by_lua_block{}}' . "\n",
        ];
    }
}
