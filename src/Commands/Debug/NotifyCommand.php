<?php

namespace Nidavellir\Mjolnir\Commands\Debug;

use Illuminate\Console\Command;
use Nidavellir\Thor\Models\User;
use Nidavellir\Thor\Notifications\PushoverNotification;

class NotifyCommand extends Command
{
    protected $signature = 'debug:notify';

    protected $description = 'Notify testing';

    public function handle()
    {
        // Retrieve the user with ID 1
        $user = User::find(1);

        if (!$user) {
            $this->error('User with ID 1 not found.');
            return 1;
        }

        try {
            // Define the application token key
            $applicationKey = 'nidavellir'; // Replace with the appropriate key from your config

            // Debug: Confirm application token exists
            $token = config("nidavellir.apis.pushover.{$applicationKey}");
            if (!$token) {
                $this->error("Pushover application token '{$applicationKey}' is not configured.");
                return 1;
            }
            $this->info("Using Pushover token: {$token}");

            // Send the notification
            $notification = new PushoverNotification(
                'This is a test notification from the debug command.',
                $applicationKey,
                'Test Notification',
                ['priority' => 1, 'sound' => 'magic']
            );

            $notification->send($user);

            $this->info('Notification sent successfully to User ID 1.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to send notification: ' . $e->getMessage());
            return 1;
        }
    }
}
