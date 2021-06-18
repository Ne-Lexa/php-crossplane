<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Command;

use Nelexa\NginxParser\Console\Output\FileOutput;
use Nelexa\NginxParser\Crossplane;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FormatCommand extends Command
{
    protected static $defaultName = 'format';

    protected function configure(): void
    {
        $this
            ->setDescription('formats an nginx config file')
            ->addArgument('filename', InputArgument::REQUIRED, 'the nginx config file')
            ->addOption('out', 'o', InputOption::VALUE_OPTIONAL, 'write output to a file')
            ->addOption('indent', 'i', InputOption::VALUE_OPTIONAL, 'number of spaces to indent output', 4)
            ->addOption('tabs', 't', InputOption::VALUE_NONE, 'indent with tabs instead of spaces')
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
        $indent = max((int) $input->getOption('indent'), 0);
        $tabs = (bool) $input->getOption('tabs');

        if ($out !== null) {
            $out = (string) $out;
            $output = new FileOutput($out);
        }

        $crossplane = new Crossplane();
        $formattedConfig = $crossplane->formatter()->format($filename, $indent, $tabs);

        $output->writeln($formattedConfig);

        return 0;
    }
}
