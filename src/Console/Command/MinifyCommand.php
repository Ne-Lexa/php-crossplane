<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Command;

use Nelexa\NginxParser\Console\Output\FileOutput;
use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Parser;
use Nelexa\NginxParser\Util\StringUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MinifyCommand extends Command
{
    protected static $defaultName = 'minify';

    protected function configure(): void
    {
        $this
            ->setDescription('removes all whitespace from an nginx config')
            ->addArgument('filename', InputArgument::REQUIRED, 'the nginx config file')
            ->addOption('out', 'o', InputOption::VALUE_OPTIONAL, 'write output to a file')
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
        $filename = (string) $input->getArgument('filename');
        $out = $input->getOption('out');

        $crossplane = new Crossplane();
        $payload = $crossplane->parser()->parse(
            $filename,
            [
                Parser::OPTION_SINGLE_FILE => true,
                Parser::OPTION_CATCH_ERRORS => false,
                Parser::OPTION_CHECK_ARGS => false,
                Parser::OPTION_CHECK_CTX => false,
                Parser::OPTION_COMMENTS => false,
                Parser::OPTION_STRICT => false,
            ]
        );

        if ($out !== null) {
            $out = (string) $out;
            $output = new FileOutput($out);
        }

        $this->writeBlock($payload['config'][0]['parsed'], $output);
        $output->write("\n");

        return 0;
    }

    private function writeBlock(array $block, OutputInterface $output): void
    {
        foreach ($block as $stmt) {
            $output->write(StringUtil::enquote($stmt['directive']));
            if ($stmt['directive'] === 'if') {
                $output->write(
                    sprintf(' (%s)', implode(' ', array_map([StringUtil::class, 'enquote'], $stmt['args'])))
                );
            } else {
                $output->write(
                    sprintf(' %s', implode(' ', array_map([StringUtil::class, 'enquote'], $stmt['args'])))
                );
            }

            if (isset($stmt['block'])) {
                $output->write('{');
                $this->writeBlock($stmt['block'], $output);
                $output->write('}');
            } else {
                $output->write(';');
            }
        }
    }
}
