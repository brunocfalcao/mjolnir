<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\QueryAllOrdersCommand;
use Nidavellir\Mjolnir\Commands\DispatchCommand;
use Nidavellir\Mjolnir\Commands\OrderQueryCommand;
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
                OrderQueryCommand::class,
                DispatchCommand::class,
                QueryAllOrdersCommand::class,
                TestCommand::class,
            ]);
        }
    }
}
