<?php

namespace Nidavellir\Mjolnir\Concerns\Models\Account;

use Nidavellir\Thor\Models\Account;
use Nidavellir\Thor\Models\ApiSystem;
use Nidavellir\Thor\Models\User;
use Nidavellir\Mjolnir\Support\Proxies\ApiProxy;
use Nidavellir\Mjolnir\ValueObjects\ApiCredentials;

trait HasApiFeatures
{
    // Returns the account that has a specific api system canonical from an admin user.
    public static function admin(string $apiSystemCanonical)
    {
        $userAdmin = User::firstWhere('is_admin', true);
        $apiSystem = ApiSystem::firstWhere('canonical', $apiSystemCanonical);

        return Account::where(
            'user_id',
            $userAdmin->id
        )->where(
            'api_system_id',
            $apiSystem->id
        )->first();
    }

    /**
     * Return the right api client object from the ApiProxy given the account
     * connection id.
     */
    public function withApi()
    {
        return new ApiProxy(
            $this->apiSystem->canonical,
            /**
             * The credentials stored on the exchange connection are always
             * encrypted, so no need to re-encrypt them again.
             */
            new ApiCredentials(
                $this->credentials
            )
        );
    }
}
