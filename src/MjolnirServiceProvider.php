<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchAccountPositionsCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\HourlyCommand;
use Nidavellir\Mjolnir\Commands\Debug\GetAccountBalanceCommand;
use Nidavellir\Mjolnir\Commands\TestCommand;
use Nidavellir\Mjolnir\Observers\OrderApiObserver;
use Nidavellir\Thor\Models\Order;

class MjolnirServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerCommands();
        $this->registerObservers();
    }

    protected function registerObservers()
    {
        Order::observe(OrderApiObserver::class);
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchCommand::class,
                TestCommand::class,
                HourlyCommand::class,
                DispatchAccountPositionsCommand::class,

                GetAccountBalanceCommand::class,
            ]);
        }
    }
}
