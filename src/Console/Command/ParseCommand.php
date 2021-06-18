<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Command;

use Nelexa\NginxParser\Console\Output\FileOutput;
use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Parser;
use Nelexa\NginxParser\Util\JsonFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ParseCommand extends Command
{
    protected static $defaultName = 'parse';

    protected function configure(): void
    {
        $this
            ->setDescription('parses a json payload for an nginx config')
            ->addArgument('filename', InputArgument::REQUIRED, 'the nginx config file')
            ->addOption('out', 'o', InputOption::VALUE_OPTIONAL, 'write output to a file')
            ->addOption('indent', 'i', InputOption::VALUE_OPTIONAL, 'number of spaces to indent output', 0)
            ->addOption('ignore', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'ignore directives (comma-separated)', [])
            ->addOption('no-catch', null, InputOption::VALUE_NONE, 'only collect first error in file')
            ->addOption('tb-onerror', null, InputOption::VALUE_NONE, 'include tracebacks in config errors')
            ->addOption('combine', null, InputOption::VALUE_NONE, 'use includes to create one single file')
            ->addOption('single-file', null, InputOption::VALUE_NONE, 'do not include other config files')
            ->addOption('include-comments', null, InputOption::VALUE_NONE, 'include comments in json')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'raise errors for unknown directives')
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $callback = static function (\Throwable $e) {
            return $e . "\n" . $e->getTraceAsString();
        };

        $filename = (string) $input->getArgument('filename');
        $ignore = (array) $input->getOption('ignore');
        $ignore = array_merge([], ...array_map(static function (string $item) {
            return array_map('trim', explode(',', $item));
        }, $ignore));
        $catchErrors = !(bool) $input->getOption('no-catch');
        $tbOnError = (bool) $input->getOption('tb-onerror');
        $combine = (bool) $input->getOption('combine');
        $singleFile = (bool) $input->getOption('single-file');
        $includeComments = (bool) $input->getOption('include-comments');
        $strict = (bool) $input->getOption('strict');
        $out = $input->getOption('out');
        $indent = (int) $input->getOption('indent');

        if ($out !== null) {
            $out = (string) $out;
            $output = new FileOutput($out);
        }

        $onError = null;
        if ($tbOnError) {
            $onError = $callback;
        }

        $crossplane = new Crossplane();
        $payload = $crossplane->parser()->parse(
            $filename,
            [
                Parser::OPTION_ON_ERROR => $onError,
                Parser::OPTION_CATCH_ERRORS => $catchErrors,
                Parser::OPTION_IGNORE => $ignore,
                Parser::OPTION_SINGLE_FILE => $singleFile,
                Parser::OPTION_COMMENTS => $includeComments,
                Parser::OPTION_STRICT => $strict,
                Parser::OPTION_COMBINE => $combine,
            ]
        );

        $formatter = new JsonFormatter($indent);
        $output->writeln($formatter->format($payload));

        return 0;
    }
}
