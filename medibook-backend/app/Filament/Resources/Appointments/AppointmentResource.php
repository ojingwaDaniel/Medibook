<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['patient', 'doctor.user']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('starts_at')
                    ->label('When (doctor local time)')
                    ->formatStateUsing(fn ($state, Appointment $record) => Carbon::parse($state)
                        ->setTimezone($record->doctor->timezone)
                        ->format('D d M Y, H:i'))
                    ->sortable(),
                TextColumn::make('patient.name')->label('Patient')->searchable(),
                TextColumn::make('doctor.user.name')->label('Doctor')->searchable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'confirmed' => 'info',
                        'completed' => 'success',
                        'cancelled' => 'gray',
                        'no_show' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reason')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_reason')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'confirmed' => 'Confirmed',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'no_show' => 'No-show',
                ]),
                Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('starts_at', '>=', now())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAppointments::route('/')];
    }
}