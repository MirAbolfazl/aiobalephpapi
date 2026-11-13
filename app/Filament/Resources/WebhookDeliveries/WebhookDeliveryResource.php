<?php

namespace App\Filament\Resources\WebhookDeliveries;

use App\Filament\Resources\WebhookDeliveries\Pages\ManageWebhookDeliveries;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'تحویل وب‌هوک';
    protected static ?string $modelLabel = 'تحویل وب‌هوک';
    protected static ?string $pluralModelLabel = 'تحویل‌های وب‌هوک';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('شناسه')->sortable(),
                TextColumn::make('endpoint.url')->label('مقصد')->limit(40),
                TextColumn::make('event_name')->label('رویداد')->badge(),
                TextColumn::make('status')->label('وضعیت')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'delivered' => 'success',
                        'failed', 'cancelled' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('attempts')->label('تلاش‌ها')->sortable(),
                TextColumn::make('response_code')->label('کد پاسخ'),
                TextColumn::make('created_at')->label('زمان')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWebhookDeliveries::route('/'),
        ];
    }
}
