<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Ext;

use Nelexa\NginxParser\Crossplane;

interface CrossplaneExtension
{
    public function registerExtension(Crossplane $crossplane): void;

    public function lex(\Iterator $charIterator, string $directive): ?\Generator;

    public function build(array $stmt, string $padding, int $indent = 4, bool $tabs = false): string;
}
