<?php

namespace Nidavellir\Mjolnir\Abstracts;

abstract class BaseIndicator
{
    protected array $data;

    public string $id;

    public string $endpoint;

    public string $type;

    public function load(array $data): void
    {
        $this->data = $data;
    }
}
