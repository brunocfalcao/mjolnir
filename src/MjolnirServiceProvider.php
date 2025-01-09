<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchCoreJobsQueueCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchPositionsCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\RefreshBaseDataCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\SyncOrdersCommand;
use Nidavellir\Mjolnir\Commands\Debug\ClosePositionCommand;
use Nidavellir\Mjolnir\Commands\Debug\GetAccountBalanceCommand;
use Nidavellir\Mjolnir\Commands\Debug\PlaceOrderCommand;
use Nidavellir\Mjolnir\Commands\Debug\QueryOrderCommand;
use Nidavellir\Mjolnir\Commands\Debug\QueryPositionsCommand;
use Nidavellir\Mjolnir\Commands\Debug\QueryTradeCommand;
use Nidavellir\Mjolnir\Commands\TestCommand;
use Nidavellir\Mjolnir\Observers\OrderApiObserver;
use Nidavellir\Mjolnir\Observers\PositionApiObserver;
use Nidavellir\Thor\Models\Order;
use Nidavellir\Thor\Models\Position;

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
        Position::observe(PositionApiObserver::class);
    }

    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Cronjobs.
                DispatchCoreJobsQueueCommand::class,
                RefreshBaseDataCommand::class,
                DispatchPositionsCommand::class,
                SyncOrdersCommand::class,

                // Debug.
                QueryTradeCommand::class,
                ClosePositionCommand::class,
                TestCommand::class,
                GetAccountBalanceCommand::class,
                QueryOrderCommand::class,
                QueryPositionsCommand::class,
                PlaceOrderCommand::class,
            ]);
        }
    }
}
