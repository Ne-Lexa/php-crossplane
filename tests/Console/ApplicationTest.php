<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Tests\Console;

use Nelexa\NginxParser\Console\Application;
use Nelexa\NginxParser\Crossplane;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @small
 */
class ApplicationTest extends TestCase
{
    public function testLogo(): void
    {
        $name = 'nginx-config-parser';
        $logo = "~~~ LOGO ~~~\n";

        $application = new Application($name, $logo);
        static::assertSame($application->getName(), $name);
        static::assertSame($application->getVersion(), Crossplane::VERSION);
        static::assertStringStartsWith($logo, $application->getHelp());
    }
}
