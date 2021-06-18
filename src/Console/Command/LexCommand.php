<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Command;

use Nelexa\NginxParser\Console\Output\FileOutput;
use Nelexa\NginxParser\Crossplane;
use Nelexa\NginxParser\Util\JsonFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LexCommand extends Command
{
    protected static $defaultName = 'lex';

    protected function configure(): void
    {
        $this
            ->setDescription('lexes tokens from an nginx config file')
            ->addArgument('filename', InputArgument::REQUIRED, 'the nginx config file')
            ->addOption('out', 'o', InputOption::VALUE_OPTIONAL, 'write output to a file')
            ->addOption('indent', 'i', InputOption::VALUE_OPTIONAL, 'number of spaces to indent output', 0)
            ->addOption('line-numbers', 'l', InputOption::VALUE_NONE, 'include line numbers in json payload')
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
        $filename = $input->getArgument('filename');
        $lineNumbers = (bool) $input->getOption('line-numbers');
        $out = $input->getOption('out');
        $indent = (int) $input->getOption('indent');

        if ($out !== null) {
            $out = (string) $out;
            $output = new FileOutput($out);
        }

        $crossplane = new Crossplane();
        $lex = iterator_to_array($crossplane->lexer()->lex($filename));
        if ($lineNumbers) {
            $payload = array_map(static function ($data) {
                return [$data[0], $data[1]];
            }, $lex);
        } else {
            $payload = array_map(static function ($data) {
                return [$data[0]];
            }, $lex);
        }

        $formatter = new JsonFormatter($indent);
        $output->writeln($formatter->format($payload));

        return 0;
    }
}
