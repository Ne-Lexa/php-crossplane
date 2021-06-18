<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Exception;

class NgxParserException extends \Exception
{
    /** @var string */
    private $filename;

    /** @var int|null */
    private $lineNo;

    public function __construct($message, string $filename, ?int $lineNo = null, \Throwable $previous = null, int $code = 0)
    {
        parent::__construct($message, $code, $previous);
        $this->filename = $filename;
        $this->lineNo = $lineNo;
    }

    public function __toString(): string
    {
        if ($this->lineNo !== null) {
            return sprintf('%s in %s:%s', $this->message, $this->filename, $this->lineNo);
        }

        return sprintf('%s in %s', $this->message, $this->filename);
    }

    /**
     * @return string
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @return int|null
     */
    public function getLineNo(): ?int
    {
        return $this->lineNo;
    }
}
