<?php

namespace Nidavellir\Mjolnir\Commands\Cronjobs;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\User;
use OpenAI\Laravel\Facades\OpenAI;
use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\Position;
use Nidavellir\Thor\Models\AccountBalanceHistory;

class CheckForTokenNewsCommand extends Command
{
    protected $signature = 'mjolnir:check-token-news';

    protected $description = 'Enquires chatGPT to check for token news, to see if there are issues with a token';

    public function handle()
    {
        $text = "I am a crypto trading application. I need you to browse the internet, and let me know
        if there are tokens from Binance that have at the moment issues, like being delisted, or issues
        that the price might drop or is dropping. Can you tell me if you see any crypto tokens with
        issues?";


        $result = OpenAI::chat()->create([
            'model' => 'chatgpt-4o-latest',
            'messages' => [
                ['role' => 'user', 'content' => $text],
            ],
        ]);

        $this->info($result->choices[0]->message->content);
    }
}
