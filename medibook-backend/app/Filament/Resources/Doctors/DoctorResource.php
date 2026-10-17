<?php

namespace App\Filament\Resources\Doctors;

use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Models\Doctor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DoctorResource extends Resource
{
    protected static ?string $model = Doctor::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false; // doctors register through the app
    }

    /** Shows how many doctors are waiting for approval. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Doctor::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'approved' => 'success',
            'pending' => 'warning',
            'suspended' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Doctor')->columns(2)->schema([
                TextEntry::make('user.name')->label('Name'),
                TextEntry::make('user.email')->label('Email'),
                TextEntry::make('user.phone')->label('Phone'),
                TextEntry::make('specialty.name')->label('Specialty'),
                TextEntry::make('years_experience')->label('Years of experience'),
                TextEntry::make('consultation_fee')->numeric(decimalPlaces: 2),
                TextEntry::make('status')->badge()
                    ->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('slot_minutes')->label('Slot length (min)'),
            ]),
            Section::make('Clinic')->columns(2)->schema([
                TextEntry::make('clinic_name'),
                TextEntry::make('clinic_address'),
                TextEntry::make('timezone'),
                TextEntry::make('bio')->columnSpanFull()->placeholder('No bio'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'specialty']))
            ->columns([
                TextColumn::make('user.name')->label('Doctor')->searchable(),
                TextColumn::make('specialty.name')->label('Specialty'),
                TextColumn::make('clinic_name')->searchable(),
                TextColumn::make('consultation_fee')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('created_at')->label('Registered')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'suspended' => 'Suspended',
                ]),
                SelectFilter::make('specialty')->relationship('specialty', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The doctor will appear in patient search and can receive bookings.')
                    ->visible(fn (Doctor $record) => $record->status !== 'approved')
                    ->action(function (Doctor $record) {
                        $record->approve();
                        Notification::make()->title('Doctor approved')->success()->send();
                    }),

                Action::make('suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(function (Doctor $record) {
                        $upcoming = $record->appointments()
                            ->where('status', 'confirmed')
                            ->where('starts_at', '>', now())
                            ->count();

                        return "The doctor will disappear from patient search and lose access to doctor features. "
                            ."{$upcoming} upcoming confirmed appointment(s) stay on the books, so contact those patients.";
                    })
                    ->visible(fn (Doctor $record) => $record->status === 'approved')
                    ->action(function (Doctor $record) {
                        $record->suspend();
                        Notification::make()->title('Doctor suspended')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDoctors::route('/')];
    }
}