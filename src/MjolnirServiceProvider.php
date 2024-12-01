<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\UpsertRecvWindowsCommand;
use Nidavellir\Mjolnir\Commands\DispatchCommand;
use Nidavellir\Mjolnir\Commands\OrderQueryCommand;

class MjolnirServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerCommands();
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                OrderQueryCommand::class,
                DispatchCommand::class,
                UpsertRecvWindowsCommand::class,
            ]);
        }
    }
}
