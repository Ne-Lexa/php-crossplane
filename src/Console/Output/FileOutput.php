<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Console\Output;

use Symfony\Component\Console\Output\StreamOutput;

class FileOutput extends StreamOutput
{
    /** @var resource */
    private $fp;

    /**
     * @param string $outFilename
     *
     * @throws \Exception
     */
    public function __construct(string $outFilename)
    {
        parent::__construct($this->fp = $this->openOutputStream($outFilename));
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param string $outFilename
     *
     * @return resource
     */
    private function openOutputStream(string $outFilename)
    {
        set_error_handler(
            static function (int $errorNumber, string $errorString): ?bool {
                throw new \InvalidArgumentException($errorString, $errorNumber);
            }
        );
        $fp = fopen($outFilename, 'w+b');
        restore_error_handler();

        return $fp;
    }

    protected function hasColorSupport(): bool
    {
        return false;
    }

    public function close(): void
    {
        if (\is_resource($this->fp)) {
            fclose($this->fp);
        }
    }
}
