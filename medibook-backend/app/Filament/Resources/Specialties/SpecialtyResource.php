<?php

namespace App\Filament\Resources\Specialties;

use App\Filament\Resources\Specialties\Pages\ManageSpecialties;
use App\Models\Specialty;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialtyResource extends Resource
{
    protected static ?string $model = Specialty::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug'),
                TextColumn::make('doctors_count')->counts('doctors')->label('Doctors'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Specialty $record) => $record->doctors()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSpecialties::route('/')];
    }
}