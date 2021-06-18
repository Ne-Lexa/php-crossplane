<?php

declare(strict_types=1);

namespace Nelexa\NginxParser;

use Nelexa\NginxParser\Exception\NgxParserIOException;
use Nelexa\NginxParser\Util\FileUtil;
use Nelexa\NginxParser\Util\StringUtil;

class Builder
{
    /** @var array<string, callable> */
    private $externalBuilders = [];

    public function build(array $payload, $indent = 4, $tabs = false, $header = false): string
    {
        $padding = $tabs ? "\t" : str_repeat(' ', $indent);

        $head = '';
        if ($header) {
            $head .= "# This config was built from JSON using NGINX crossplane.\n";
            $head .= "# If you encounter any bugs please report them here:\n";
            $head .= "# https://github.com/Ne-Lexa/php-crossplane/issues\n";
            $head .= "\n";
        }

        $buildBlock = function (string $output, iterable $block, int $depth, int &$lastLine) use ($tabs, $indent, $padding, &$buildBlock) {
            $margin = str_repeat($padding, $depth);

            foreach ($block as $stmt) {
                $directive = Util\StringUtil::enquote($stmt['directive']);
                $line = $stmt['line'] ?? 0;

                if ($directive === '#' && $line === $lastLine) {
                    $output .= ' #' . $stmt['comment'];

                    continue;
                }

                if ($directive === '#') {
                    $built = '#' . $stmt['comment'];
                } elseif (isset($this->externalBuilders[$directive])) {
                    $externalBuilder = $this->externalBuilders[$directive];
                    $built = $externalBuilder($stmt, $padding, $indent, $tabs);
                } else {
                    $args = array_map([StringUtil::class, 'enquote'], $stmt['args']);

                    if ($directive === 'if') {
                        $built = 'if (' . implode(' ', $args) . ')';
                    } elseif (!empty($args)) {
                        $built = $directive . ' ' . implode(' ', $args);
                    } else {
                        $built = $directive;
                    }

                    if (isset($stmt['block'])) {
                        $built .= ' {';
                        $built = $buildBlock($built, $stmt['block'], $depth + 1, $line);
                        $built .= "\n" . $margin . '}';
                    } else {
                        $built .= ';';
                    }
                }
                $output .= ($output ? "\n" : '') . $margin . $built;
                $lastLine = $line;
            }

            return $output;
        };

        $body = '';
        $lastLine = 0;
        $body = $buildBlock($body, $payload, 0, $lastLine);

        return $head . $body;
    }

    /**
     * Uses a full nginx config payload (output of crossplane.parse) to build
     * config files, then writes those files to disk.
     *
     * @param array       $payload
     * @param string|null $dirname
     * @param int         $indent
     * @param bool        $tabs
     * @param bool        $header
     *
     * @throws NgxParserIOException
     */
    public function buildFiles(
        array $payload,
        ?string $dirname = null,
        int $indent = 4,
        bool $tabs = false,
        bool $header = false
    ): void {
        if ($dirname === null || $dirname === '') {
            $dirname = getcwd();
        }

        foreach ($payload['config'] as $config) {
            $path = $config['file'];
            if (!FileUtil::isAbsolute($path)) {
                $path = rtrim($dirname, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . $path;
            }

            // make directories that need to be made for the config to be built
            $dirPath = \dirname($path);
            if (!is_dir($dirPath) && !mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                throw new NgxParserIOException(sprintf('Directory "%s" was not created', $dirPath), $path);
            }

            // build then create the nginx config file using the json payload
            $parsed = $config['parsed'];
            $output = $this->build($parsed, $indent, $tabs, $header);
            $output = rtrim($output) . "\n";

            if (file_put_contents($path, $output) === false) {
                throw new NgxParserIOException(sprintf('Error save file %s', $path), $path);
            }
        }
    }

    public function registerExternalBuilder(callable $builder, array $directives): void
    {
        foreach ($directives as $directive) {
            $this->externalBuilders[$directive] = $builder;
        }
    }
}
