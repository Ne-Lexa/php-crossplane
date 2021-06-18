<?php

declare(strict_types=1);

namespace Nelexa\NginxParser\Util;

class EmplaceIterator implements \Iterator
{
    /** @var \Iterator */
    private $iterator;

    /** @var array */
    private $ret = [];

    /**
     * @param \Iterator $iterator
     */
    public function __construct(\Iterator $iterator)
    {
        $this->iterator = $iterator;
    }

    /**
     * @return mixed
     */
    public function current()
    {
        if (!empty($this->ret)) {
            return array_pop($this->ret);
        }

        return $this->iterator->current();
    }

    public function next(): void
    {
        if (!empty($this->ret)) {
            return;
        }
        $this->iterator->next();
    }

    /**
     * @return bool|float|int|string|null
     */
    public function key()
    {
        return $this->iterator->key();
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    public function rewind(): void
    {
        // no rewind
    }

    public function putBack($v): void
    {
        $this->ret[] = $v;
    }
}
