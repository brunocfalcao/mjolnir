<?php

namespace Nidavellir\Mjolnir\Abstracts;

abstract class BaseIndicator
{
    protected array $data;

    protected array $totalIndicatorsData;

    public string $id;

    public string $endpoint;

    public string $type;

    public function load(array $data, ?array $totalIndicatorsData = null): void
    {
        $this->totalIndicatorsData = $totalIndicatorsData;

        $this->data = $data;
    }
}
