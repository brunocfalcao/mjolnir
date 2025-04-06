<?php

namespace Nidavellir\Mjolnir;

use Illuminate\Support\ServiceProvider;
use Nidavellir\Mjolnir\Commands\Cronjobs\DailyReportCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchCoreJobQueueCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\DispatchPositionsCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\GetBinancePricesCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\IdentifyOrphanOrdersCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\NotifyHighMarginRatioCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\OptimizeCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\PurgeDataCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\RefreshDataCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\RunIntegrityChecksCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\StorePositionIndicatorsCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\SyncOrdersCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\UpdateAccountsBalancesCommand;
use Nidavellir\Mjolnir\Commands\Cronjobs\UpdateRecvwindowSafetyDurationCommand;
use Nidavellir\Mjolnir\Commands\Debug\ClosePositionCommand;
use Nidavellir\Mjolnir\Commands\Debug\ComputeWAPCommand;
use Nidavellir\Mjolnir\Commands\Debug\GetAccountBalanceCommand;
use Nidavellir\Mjolnir\Commands\Debug\IndicatorCommand;
use Nidavellir\Mjolnir\Commands\Debug\NotifyCommand;
use Nidavellir\Mjolnir\Commands\Debug\PlaceOrderCommand;
use Nidavellir\Mjolnir\Commands\Debug\PlacePositionCommand;
use Nidavellir\Mjolnir\Commands\Debug\PlaceStopMarketOrderCommand;
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
                UpdateRecvwindowSafetyDurationCommand::class,
                DispatchCoreJobQueueCommand::class,
                RefreshDataCommand::class,
                DispatchPositionsCommand::class,
                SyncOrdersCommand::class,
                NotifyHighMarginRatioCommand::class,
                OptimizeCommand::class,
                GetBinancePricesCommand::class,
                DailyReportCommand::class,
                IdentifyOrphanOrdersCommand::class,
                RunIntegrityChecksCommand::class,
                PurgeDataCommand::class,
                UpdateAccountsBalancesCommand::class,
                StorePositionIndicatorsCommand::class,

                // Debug.
                ComputeWAPCommand::class,
                IndicatorCommand::class,
                PlacePositionCommand::class,
                PlaceStopMarketOrderCommand::class,
                NotifyCommand::class,
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
