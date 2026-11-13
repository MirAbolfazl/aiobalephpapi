<?php

namespace App\Filament\Resources\BaleAccounts;

use App\Filament\Resources\BaleAccounts\Pages\ManageBaleAccounts;
use App\Models\BaleAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BaleAccountResource extends Resource
{
    protected static ?string $model = BaleAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'حساب‌های بله';
    protected static ?string $modelLabel = 'حساب بله';
    protected static ?string $pluralModelLabel = 'حساب‌های بله';

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
                TextColumn::make('label')->label('عنوان')->searchable(),
                TextColumn::make('phone')->label('تلفن'),
                TextColumn::make('bale_user_id')->label('شناسهٔ بله')->sortable(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
                TextColumn::make('last_used_at')->label('آخرین استفاده')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBaleAccounts::route('/'),
        ];
    }
}
