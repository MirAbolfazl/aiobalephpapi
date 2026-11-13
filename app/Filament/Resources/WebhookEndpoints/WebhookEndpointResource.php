<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Filament\Resources\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use App\Models\WebhookEndpoint;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'وب‌هوک‌ها';
    protected static ?string $modelLabel = 'وب‌هوک';
    protected static ?string $pluralModelLabel = 'وب‌هوک‌ها';

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
                TextColumn::make('user.name')->label('کاربر API')->searchable(),
                TextColumn::make('baleAccount.label')->label('حساب بله')->searchable(),
                TextColumn::make('url')->label('نشانی')->limit(55)->searchable(),
                TextColumn::make('events')->label('رویدادها')->badge(),
                ToggleColumn::make('is_active')->label('فعال'),
                TextColumn::make('last_delivered_at')->label('آخرین تحویل')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWebhookEndpoints::route('/'),
        ];
    }
}
