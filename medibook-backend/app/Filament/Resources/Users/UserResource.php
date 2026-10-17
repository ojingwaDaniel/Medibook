<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('role')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'admin' => 'danger',
                        'doctor' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')->options([
                    'patient' => 'Patient',
                    'doctor' => 'Doctor',
                    'admin' => 'Admin',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                Action::make('deactivate')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('They will be signed out of the app immediately and cannot log in again.')
                    ->visible(fn (User $record) => $record->is_active && $record->id !== auth()->id())
                    ->action(function (User $record) {
                        $record->deactivate();
                        Notification::make()->title('User deactivated')->success()->send();
                    }),

                Action::make('activate')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => ! $record->is_active)
                    ->action(function (User $record) {
                        $record->activate();
                        Notification::make()->title('User activated')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}