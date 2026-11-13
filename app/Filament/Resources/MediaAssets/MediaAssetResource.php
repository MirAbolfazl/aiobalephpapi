<?php

namespace App\Filament\Resources\MediaAssets;

use App\Filament\Resources\MediaAssets\Pages\ManageMediaAssets;
use App\Models\MediaAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $navigationLabel = 'فایل‌ها';
    protected static ?string $modelLabel = 'فایل';
    protected static ?string $pluralModelLabel = 'فایل‌ها';

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
                TextColumn::make('original_name')->label('نام فایل')->searchable(),
                TextColumn::make('mime_type')->label('نوع'),
                TextColumn::make('size')->label('حجم')->formatStateUsing(
                    fn (int $state): string => number_format($state / 1024, 1) . ' KB',
                )->sortable(),
                TextColumn::make('sha256')->label('SHA-256')->limit(16),
                TextColumn::make('created_at')->label('زمان')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMediaAssets::route('/'),
        ];
    }
}
