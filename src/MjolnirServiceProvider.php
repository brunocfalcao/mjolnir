<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\DispatchCommand;
use Nidavellir\Mjolnir\Commands\TestCommand;

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
                DispatchCommand::class,
                TestCommand::class,
            ]);
        }
    }
}
