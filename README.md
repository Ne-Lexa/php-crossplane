<p align="center">
  <img src="logo.svg" alt="crossplane"/>
</p>

# php-crossplane
**Reliable and fast NGINX configuration file parser and builder**

:information_source: This is a PHP port of the Nginx Python crossplane package which can be found [here](https://github.com/nginxinc/crossplane).

[![Packagist Version](https://img.shields.io/packagist/v/nelexa/crossplane.svg)](https://packagist.org/packages/nelexa/crossplane)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/nelexa/crossplane)
[![Build Status](https://github.com/Ne-Lexa/php-crossplane/workflows/build/badge.svg)](https://github.com/Ne-Lexa/php-crossplane/actions)
[![License](https://img.shields.io/packagist/l/nelexa/crossplane.svg)](https://github.com/Ne-Lexa/crossplane/blob/master/LICENSE)

* [Install](#install)
* [Use in PHP](#use-in-php)
  * [Parse config](#parse-config)
  * [Build config from payload](#build-config-from-payload)
  * [Lex config](#lex-config)
    * [Register custom Lexer / Builder directives](#register-custom-lexer--builder-directives)
* [Command Line Interface](#command-line-interface)
  * [crossplane parse](#crossplane-parse)
    * [Schema](#schema)
    * [Example](#example)
    * [crossplane parse (advanced)](#crossplane-parse-advanced)
  * [crossplane build](#crossplane-build)
  * [crossplane lex](#crossplane-lex)
    * [Example](#example-1)
  * [crossplane format](#crossplane-format)
  * [crossplane minify](#crossplane-minify)

## Install
Install in project
```bash
composer require nelexa/crossplane
```
Global install
```bash
composer require --global nelexa/crossplane
```

## Use in PHP

```php
$crossplane = new \Nelexa\NginxParser\Crossplane();

$lexer = $crossplane->lexer(); // gets \Nelexa\NginxParser\Lexer instance
$builder = $crossplane->builder(); // gets \Nelexa\NginxParser\Builder instance
$parser = $crossplane->parser(); // gets \Nelexa\NginxParser\Parser instance
$analyzer = $crossplane->analyzer(); // gets \Nelexa\NginxParser\Analyzer instance
$formatter = $crossplane->formatter(); // gets \Nelexa\NginxParser\Formatter instance
```

### Parse config
```php
$nginxConfigFile = '/etc/nginx/nginx.conf';
$crossplane = new \Nelexa\NginxParser\Crossplane();
$payload = $crossplane->parser()->parse($nginxConfigFile, $parseOptions = [
    \Nelexa\NginxParser\Parser::OPTION_COMMENTS => true,
    \Nelexa\NginxParser\Parser::OPTION_COMBINE => true,
    // etc...
]);
```
This will return the same payload as described in the [crossplane
parse](#crossplane-parse) section.

### Build config from payload

```php
$crossplane = new \Nelexa\NginxParser\Crossplane();
$config = $crossplane->builder()->build(
    [[
        'directive' => 'events',
        'args' => [],
        'block' => [[
            'directive' => 'worker_connections',
            'args' => ['1024'],
        ]],
    ]]
);
```

This will return a single string that contains an entire NGINX config
file.
```
events {
    worker_connections 1024;
}
```

### Lex config

```php
$crossplane = new \Nelexa\NginxParser\Crossplane();
$tokensIterator = $crossplane->lexer()->lex('/etc/nginx/nginx.conf');
$tokensArray = iterator_to_array($tokensIterator);
```

`$crossplane->lexer()->lex()` generates 3-tuples.
```php
[
    [
        'user', // token
        1,      // line
        false,  // quote
    ],
    [
        'www-data',
        1,
        false,
    ],
    [
        ';',
        1,
        false,
    ],
    [
        'worker_processes',
        2,
        false,
    ],
    [
        'auto',
        2,
        false,
    ],
    [
        ';',
        2,
        false,
    ],
    [
        'pid',
        3,
        false,
    ],
    [
        '/run/nginx.pid',
        3,
        false,
    ],
    [
        ';',
        3,
        false,
    ],
    // etc
]
```
#### Register custom Lexer / Builder directives
```php
$crossplane->registerExtension(new class() implements \Nelexa\NginxParser\Ext\CrossplaneExtension {
public function registerExtension(\Nelexa\NginxParser\Crossplane $crossplane): void
{
$crossplane->lexer()->registerExternalLexer(/* args */);
$crossplane->builder()->registerExternalBuilder(/* args */);
}

    public function lex(\Iterator $charIterator, string $directive): ?\Generator
    {
    }

    public function build(array $stmt, string $padding, int $indent = 4, bool $tabs = false): string
    {
    }
});
```

## Command Line Interface
To invoke commands in the command line interface, use `vendor/bin/crossplane` if you have installed the package locally in the project, and `crossplane` if you have installed the package globally.

```
Usage:
  vendor/bin/crossplane command [options] [arguments]

Options:
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi            Force ANSI output
      --no-ansi         Disable ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

Available commands:
  build   builds an nginx config from a json payload
  format  formats an nginx config file
  help    Display help for a command
  lex     lexes tokens from an nginx config file
  minify  removes all whitespace from an nginx config
  parse   parses a json payload for an nginx config
```

### crossplane parse

This command will take a path to a main NGINX config file as input, then
parse the entire config into the schema defined below, and dumps the
entire thing as a JSON payload.

```
Description:
  parses a json payload for an nginx config

Usage:
  vendor/bin/crossplane parse [options] [--] <filename>

Arguments:
  filename                the nginx config file

Options:
  -o, --out[=OUT]         write output to a file
  -i, --indent[=INDENT]   number of spaces to indent output [default: 0]
      --ignore[=IGNORE]   ignore directives (comma-separated) (multiple values allowed)
      --no-catch          only collect first error in file
      --tb-onerror        include tracebacks in config errors
      --combine           use includes to create one single file
      --single-file       do not include other config files
      --include-comments  include comments in json
      --strict            raise errors for unknown directives
  -h, --help              Display this help message
  -q, --quiet             Do not output any message
  -V, --version           Display this application version
      --ansi              Force ANSI output
      --no-ansi           Disable ANSI output
  -n, --no-interaction    Do not ask any interactive question
  -v|vv|vvv, --verbose    Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

**Privacy and Security**

Since `crossplane` is usually used to create payloads that are sent to
different servers, it's important to keep security in mind. For that
reason, the `--ignore` option was added. It can be used to keep certain
sensitive directives out of the payload output entirely.

For example, we always use the equivalent of this flag in the [NGINX Amplify
Agent](https://github.com/nginxinc/nginx-amplify-agent/) out of respect
for our users'
privacy:

    --ignore=auth_basic_user_file,secure_link_secret,ssl_certificate_key,ssl_client_certificate,ssl_password_file,ssl_stapling_file,ssl_trusted_certificate

#### Schema

**Response Object**

```js
{
    "status": String, // "ok" or "failed" if "errors" is not empty
    "errors": Array,  // aggregation of "errors" from Config objects
    "config": Array   // Array of Config objects
}
```

**Config Object**

```js
{
    "file": String,   // the full path of the config file
    "status": String, // "ok" or "failed" if errors is not empty array
    "errors": Array,  // Array of Error objects
    "parsed": Array   // Array of Directive objects
}
```

**Directive Object**

```js
{
    "directive": String, // the name of the directive
    "line": Number,      // integer line number the directive started on
    "args": Array,       // Array of String arguments
    "includes": Array,   // Array of integers (included iff this is an include directive)
    "block": Array       // Array of Directive Objects (included iff this is a block)
}
```

<div class="note">

<div class="admonition-title">

Note

</div>

If this is an `include` directive and the `--single-file` flag was not
used, an `"includes"` value will be used that holds an Array of indices
of the configs that are included by this directive.

If this is a block directive, a `"block"` value will be used that holds
an Array of more Directive Objects that define the block context.

</div>

**Error Object**

```js
{
    "file": String,     // the full path of the config file
    "line": Number,     // integer line number the directive that caused the error
    "error": String,    // the error message
    "callback": Object  // only included iff an "onerror" function was passed to parse()
}
```

<div class="note">

<div class="admonition-title">

Note

</div>

If the `--tb-onerror` flag was used by crossplane parse, `"callback"`
will contain a string that represents the traceback that the error
caused.

</div>

#### Example

The main NGINX config file is at `/etc/nginx/nginx.conf`:

```nginx
events {
    worker_connections 1024;
}

http {
    include conf.d/*.conf;
}
```

And this config file is at `/etc/nginx/conf.d/servers.conf`:

```nginx
server {
    listen 8080;
    location / {
        try_files 'foo bar' baz;
    }
}

server {
    listen 8081;
    location / {
        return 200 'success!';
    }
}
```

So then if you run this:

    vendor/bin/crossplane parse --indent=4 /etc/nginx/nginx.conf

The prettified JSON output would look like this:

```js
{
    "status": "ok",
    "errors": [],
    "config": [
        {
            "file": "/etc/nginx/nginx.conf",
            "status": "ok",
            "errors": [],
            "parsed": [
                {
                    "directive": "events",
                    "line": 1,
                    "args": [],
                    "block": [
                        {
                            "directive": "worker_connections",
                            "line": 2,
                            "args": [
                                "1024"
                            ]
                        }
                    ]
                },
                {
                    "directive": "http",
                    "line": 5,
                    "args": [],
                    "block": [
                        {
                            "directive": "include",
                            "line": 6,
                            "args": [
                                "conf.d/*.conf"
                            ],
                            "includes": [
                                1
                            ]
                        }
                    ]
                }
            ]
        },
        {
            "file": "/etc/nginx/conf.d/servers.conf",
            "status": "ok",
            "errors": [],
            "parsed": [
                {
                    "directive": "server",
                    "line": 1,
                    "args": [],
                    "block": [
                        {
                            "directive": "listen",
                            "line": 2,
                            "args": [
                                "8080"
                            ]
                        },
                        {
                            "directive": "location",
                            "line": 3,
                            "args": [
                                "/"
                            ],
                            "block": [
                                {
                                    "directive": "try_files",
                                    "line": 4,
                                    "args": [
                                        "foo bar",
                                        "baz"
                                    ]
                                }
                            ]
                        }
                    ]
                },
                {
                    "directive": "server",
                    "line": 8,
                    "args": [],
                    "block": [
                        {
                            "directive": "listen",
                            "line": 9,
                            "args": [
                                "8081"
                            ]
                        },
                        {
                            "directive": "location",
                            "line": 10,
                            "args": [
                                "/"
                            ],
                            "block": [
                                {
                                    "directive": "return",
                                    "line": 11,
                                    "args": [
                                        "200",
                                        "success!"
                                    ]
                                }
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}
```

#### crossplane parse (advanced)

This tool uses two flags that can change how `crossplane` handles
errors.

The first, `--no-catch`, can be used if you'd prefer that crossplane
quit parsing after the first error it finds.

The second, `--tb-onerror`, will add a `"callback"` key to all error
objects in the JSON output, each containing a string representation of
the traceback that would have been raised by the parser if the exception
had not been caught. This can be useful for logging purposes.

### crossplane build

This command will take a path to a file as input. The file should
contain a JSON representation of an NGINX config that has the structure
defined above. Saving and using the output from `crossplane parse` to
rebuild your config files should not cause any differences in content
except for the formatting.

```
Description:
  builds an nginx config from a json payload

Usage:
  vendor/bin/crossplane build [options] [--] <filename>

Arguments:
  filename               the file with the config payload

Options:
  -d, --dir[=DIR]        the base directory to build in [default: $PWD]
  -f, --force            overwrite existing files
  -i, --indent[=INDENT]  number of spaces to indent output [default: 4]
  -t, --tabs             indent with tabs instead of spaces
      --no-headers       do not write header to configs
      --stdout           write configs to stdout instead
  -h, --help             Display this help message
  -q, --quiet            Do not output any message
  -V, --version          Display this application version
      --ansi             Force ANSI output
      --no-ansi          Disable ANSI output
  -n, --no-interaction   Do not ask any interactive question
  -v|vv|vvv, --verbose   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### crossplane lex

This command takes an NGINX config file, splits it into tokens by
removing whitespace and comments, and dumps the list of tokens as a JSON
array.

```
Description:
  lexes tokens from an nginx config file

Usage:
  vendor/bin/crossplane lex [options] [--] <filename>

Arguments:
  filename               the nginx config file

Options:
  -o, --out[=OUT]        write output to a file
  -i, --indent[=INDENT]  number of spaces to indent output [default: 0]
  -l, --line-numbers     include line numbers in json payload
  -h, --help             Display this help message
  -q, --quiet            Do not output any message
  -V, --version          Display this application version
      --ansi             Force ANSI output
      --no-ansi          Disable ANSI output
  -n, --no-interaction   Do not ask any interactive question
  -v|vv|vvv, --verbose   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

#### Example

Passing in this NGINX config file at `/etc/nginx/nginx.conf`:

```nginx
events {
    worker_connections 1024;
}

http {
    include conf.d/*.conf;
}
```

By running:

    vendor/bin/crossplane lex /etc/nginx/nginx.conf

Will result in this JSON
output:

```js
["events","{","worker_connections","1024",";","}","http","{","include","conf.d/*.conf",";","}"]
```

However, if you decide to use the `--line-numbers` flag, your output
will look
like:

```js
[["events",1],["{",1],["worker_connections",2],["1024",2],[";",2],["}",3],["http",5],["{",5],["include",6],["conf.d/*.conf",6],[";",6],["}",7]]
```

### crossplane format

This is a quick and dirty tool that uses [crossplane
parse](#crossplane-parse) internally to format an NGINX config file.
It serves the purpose of demonstrating what you can do with `crossplane`'s
parsing abilities. It is not meant to be a fully fleshed out, feature-rich
formatting tool. If that is what you are looking for, then you may want to
look writing your own using crossplane's PHP API.

```
Description:
  formats an nginx config file

Usage:
  vendor/bin/crossplane format [options] [--] <filename>

Arguments:
  filename               the nginx config file

Options:
  -o, --out[=OUT]        write output to a file
  -i, --indent[=INDENT]  number of spaces to indent output [default: 4]
  -t, --tabs             indent with tabs instead of spaces
  -h, --help             Display this help message
  -q, --quiet            Do not output any message
  -V, --version          Display this application version
      --ansi             Force ANSI output
      --no-ansi          Disable ANSI output
  -n, --no-interaction   Do not ask any interactive question
  -v|vv|vvv, --verbose   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### crossplane minify

This is a simple and fun little tool that uses [crossplane
lex](#crossplane-lex) internally to remove as much whitespace from an
NGINX config file as possible without affecting what it does. It can't
imagine it will have much of a use to most people, but it demonstrates
the kinds of things you can do with `crossplane`'s lexing abilities.

```
Description:
  removes all whitespace from an nginx config

Usage:
  vendor/bin/crossplane minify [options] [--] <filename>

Arguments:
  filename              the nginx config file

Options:
  -o, --out[=OUT]       write output to a file
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi            Force ANSI output
      --no-ansi         Disable ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```
