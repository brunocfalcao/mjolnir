<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchCoreJobsQueueCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchPositionsCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\RefreshBaseDataCommand;
use Nidavellir\Mjolnir\Commands\Debug\GetAccountBalanceCommand;
use Nidavellir\Mjolnir\Commands\Debug\PlaceOrderCommand;
use Nidavellir\Mjolnir\Commands\Debug\QueryOrderCommand;
use Nidavellir\Mjolnir\Commands\TestCommand;
use Nidavellir\Mjolnir\Observers\OrderApiObserver;
use Nidavellir\Thor\Models\Order;

class MjolnirServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerCommands();
        $this->registerApiObservers();
    }

    protected function registerApiObservers()
    {
        Order::observe(OrderApiObserver::class);
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchCoreJobsQueueCommand::class,
                RefreshBaseDataCommand::class,
                DispatchPositionsCommand::class,

                // Debug
                TestCommand::class,
                GetAccountBalanceCommand::class,
                QueryOrderCommand::class,
                PlaceOrderCommand::class,
            ]);
        }
    }
}
