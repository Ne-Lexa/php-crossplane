<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Util;

class FileUtil
{
    public static function isAbsolute(string $path): bool
    {
        if (\DIRECTORY_SEPARATOR === '/') {
            $path = preg_replace('~^[A-Z]:~', '', $path);
        }

        return str_starts_with($path, \DIRECTORY_SEPARATOR);
    }

    public static function hasGlobMagick(string $s): bool
    {
        $magicCheckPattern = '~[*?\[]~';

        return preg_match($magicCheckPattern, $s) === 1;
    }
}
