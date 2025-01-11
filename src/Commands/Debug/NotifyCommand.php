<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\User;

class NotifyCommand extends Command
{
    protected $signature = 'debug:notify';

    protected $description = 'Notify testing';

    public function handle()
    {
        // Retrieve the user with ID 1
        $user = User::find(1);

        if (! $user) {
            $this->error('User with ID 1 not found.');

            return 1;
        }

        $user->pushover('hello there!');
    }
}
