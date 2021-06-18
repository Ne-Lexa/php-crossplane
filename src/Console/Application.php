<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console;

use Nelexa\NginxParser\Crossplane;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    /** @var string */
    private $logo;

    public function __construct(string $name, string $logo)
    {
        parent::__construct($name, Crossplane::VERSION);
        $this->logo = $logo;
    }

    public function getHelp(): string
    {
        return $this->logo . parent::getHelp();
    }
}
