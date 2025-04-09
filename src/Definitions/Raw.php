<?php

namespace Diogodg\Neoorm\Definitions;

class Raw
{
    public function __construct(
        private string $sql,
    ) {}

    public function getSql(): string
    {
        return $this->sql;
    }
}