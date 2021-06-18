<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Command;

use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Exception\NgxParserIOException;
use Nelexa\NginxParser\Util\FileUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class BuildCommand extends Command
{
    private const EXIT_ERROR_CODE_NOT_FOUND_FILE = 2;

    private const EXIT_ERROR_CODE_IS_NOT_FILE = 3;

    private const EXIT_ERROR_CODE_IS_NOT_READABLE_FILE = 3;

    private const EXIT_ERROR_CODE_INVALID_JSON = 4;

    private const EXIT_ERROR_CODE_NOT_OVERWRITE = 5;

    private const EXIT_ERROR_BUILDER_IO = 6;

    protected static $defaultName = 'build';

    protected function configure(): void
    {
        $this
            ->setDescription('builds an nginx config from a json payload')
            ->addArgument('filename', InputArgument::REQUIRED, 'the file with the config payload')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'the base directory to build in', getcwd())
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'overwrite existing files')
            ->addOption('indent', 'i', InputOption::VALUE_OPTIONAL, 'number of spaces to indent output', 4)
            ->addOption('tabs', 't', InputOption::VALUE_NONE, 'indent with tabs instead of spaces')
            ->addOption('no-headers', null, InputOption::VALUE_NONE, 'do not write header to configs')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'write configs to stdout instead')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $filename = (string) $input->getArgument('filename');
        $dirname = rtrim((string) $input->getOption('dir'), '/\\');
        $force = (bool) $input->getOption('force');
        $indent = max((int) $input->getOption('indent'), 0);
        $tabs = (bool) $input->getOption('tabs');
        $header = !((bool) $input->getOption('no-headers'));
        $stdout = (bool) $input->getOption('stdout');

        if (!file_exists($filename)) {
            $style->error(sprintf('File "%s" not found', $filename));

            return self::EXIT_ERROR_CODE_NOT_FOUND_FILE;
        }

        if (!is_file($filename)) {
            $style->error(sprintf('"%s" is not a file', $filename));

            return self::EXIT_ERROR_CODE_IS_NOT_FILE;
        }

        if (!is_readable($filename)) {
            $style->error(sprintf('File "%s" is not readable', $filename));

            return self::EXIT_ERROR_CODE_IS_NOT_READABLE_FILE;
        }

        $json = file_get_contents($filename);
        $payload = json_decode($json, true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            $style->error(sprintf('Invalid json payload file "%s": %s', $filename, json_last_error_msg()));

            return self::EXIT_ERROR_CODE_INVALID_JSON;
        }

        // find which files from the json payload will overwrite existing files
        if (!$force && !$stdout) {
            $existing = [];
            foreach ($payload['config'] ?? [] as $config) {
                $path = $config['file'];
                if (!FileUtil::isAbsolute($path)) {
                    $path = $dirname . \DIRECTORY_SEPARATOR . $path;
                }
                if (file_exists($path)) {
                    $existing[] = $path;
                }
            }

            /// ask the user if it's okay to overwrite existing files
            if (!empty($existing)) {
                $style->writeln(sprintf('<comment>building %s would overwrite these files:</comment>', $filename));
                $style->newLine();
                $style->listing($existing);
                if (!$input->isInteractive()) {
                    $style->error('No interactive mode. Files not overwritten. Use --force option.');

                    return self::EXIT_ERROR_CODE_NOT_OVERWRITE;
                }

                $answer = $style->askQuestion(new ConfirmationQuestion('overwrite?', false, '/^(y|д)/iu'));
                if (!$answer) {
                    $style->warning('not overwritten');

                    return self::EXIT_ERROR_CODE_NOT_OVERWRITE;
                }
            }
        }

        $crossplane = new Crossplane();

        // if stdout is set then just print each file after another like nginx -T
        if ($stdout) {
            foreach ($payload['config'] as $config) {
                $path = $config['file'];
                if (!FileUtil::isAbsolute($path)) {
                    $path = $dirname . \DIRECTORY_SEPARATOR . $path;
                }
                $parsed = $config['parsed'];
                $outputString = $crossplane->builder()->build($parsed, $indent, $tabs, $header);
                $outputString = rtrim($outputString) . "\n";
                $output->writeln(sprintf("# %s\n%s", $path, $outputString));
            }

            return 0;
        }

        // build the nginx configuration file from the json payload
        try {
            $crossplane->builder()->buildFiles($payload, $dirname, $indent, $tabs, $header);
        } catch (NgxParserIOException $e) {
            $style->error((string) $e);

            return self::EXIT_ERROR_BUILDER_IO;
        }

        // if verbose print the paths of the config files that were created
        if ($output->isVerbose()) {
            foreach ($payload['config'] as $config) {
                $path = $config['file'];
                if (!FileUtil::isAbsolute($path)) {
                    $path = $dirname . \DIRECTORY_SEPARATOR . $path;
                }
                $output->writeln(sprintf('wrote to <info>%s</info>', $path));
            }
        }

        return 0;
    }
}
