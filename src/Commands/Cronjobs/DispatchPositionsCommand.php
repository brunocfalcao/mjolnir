<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nidavellir\Mjolnir\Jobs\Processes\CreatePosition\CreateNewPositionsJob;
use Nidavellir\Mjolnir\Jobs\Processes\CreatePosition\VerifyPreConditionsJob;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\CoreJobQueue;
use Nidavellir\Thor\Models\ExchangeSymbol;
use Nidavellir\Thor\Models\Position;

class DispatchPositionsCommand extends Command
{
    protected $signature = 'mjolnir:dispatch-positions';

    protected $description = 'Dispatch all possible remaining positions for eligible accounts.';

    public function handle()
    {
        // Exit early if no exchange symbols exist.
        if (! ExchangeSymbol::query()->exists()) {
            return;
        }

        // Retrieve active trader accounts eligible for trading.
        $accounts = Account::whereHas('user', fn ($query) => $query->where('is_trader', true))
            ->with('user')
            ->canTrade()
            ->get();

        foreach ($accounts as $account) {
            // Fetch the number of open positions for this account.
            $openPositions = Position::active()
                ->where('account_id', $account->id)
                ->count();

            // Determine how many more positions can be opened.
            $delta = $account->max_concurrent_trades - $openPositions;

            if ($delta > 0) {
                $blockUuid = Str::uuid()->toString();

                // Queue precondition verification job.
                CoreJobQueue::create([
                    'class' => VerifyPreConditionsJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'accountId' => $account->id,
                    ],
                    'index' => 1,
                    'block_uuid' => $blockUuid,
                ]);

                // Queue job to create new positions based on delta.
                CoreJobQueue::create([
                    'class' => CreateNewPositionsJob::class,
                    'queue' => 'positions',
                    'arguments' => [
                        'accountId' => $account->id,
                        'numPositions' => $delta,
                    ],
                    'index' => 2,
                    'block_uuid' => $blockUuid,
                ]);
            }
        }

        return Command::SUCCESS;
    }
}
