<?php

namespace App\Filament\Widgets;

use App\Models\BaleAccount;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WebhookDelivery;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('کاربران API', User::count()),
            Stat::make('حساب‌های فعال بله', BaleAccount::where('is_active', true)->count()),
            Stat::make('وب‌هوک‌های در انتظار', WebhookDelivery::where('status', 'pending')->count()),
            Stat::make('حجم فایل‌ها', number_format(MediaAsset::sum('size') / 1024 / 1024, 1) . ' MB'),
        ];
    }
}
