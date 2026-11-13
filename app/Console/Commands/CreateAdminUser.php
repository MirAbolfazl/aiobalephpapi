<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\User;

#[Signature('admin:promote {email}')]
#[Description('Promote an existing API user to the Filament administrator role.')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->firstOrFail();
        $user->forceFill(['is_admin' => true])->save();
        $this->info("Administrator access granted to {$user->email}.");

        return self::SUCCESS;
    }
}
