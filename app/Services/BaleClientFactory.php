<?php

namespace App\Services;

use Aiobale\Client\Client;
use App\Models\BaleAccount;
use Illuminate\Auth\Access\AuthorizationException;

class BaleClientFactory
{
    public function forAccount(BaleAccount $account): Client
    {
        if (!$account->is_active || !$account->access_token || !$account->bale_user_id) {
            throw new AuthorizationException('The Bale account is not active.');
        }

        return (new Client())->setSessionToken(
            $account->access_token,
            (int) $account->bale_user_id,
        );
    }
}
