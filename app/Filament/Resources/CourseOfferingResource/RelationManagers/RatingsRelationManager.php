<?php

namespace App\Filament\Resources\CourseOfferingResource\RelationManagers;

use App\Models\RatingCriteria;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class RatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'ratings';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Evaluator')
                    ->options(function () {
                        $campusId = filament()->getTenant()?->id;
                        return User::whereHas('campuses', fn($q) => $q->where('campus_id', $campusId))
                            ->where('is_student', true)
                            ->get()
                            ->mapWithKeys(fn($u) => [$u->id => "{$u->student_id} - {$u->full_name}"]);
                    })
                    ->required()
                    ->searchable(),

                Forms\Components\Select::make('rating_criteria_id')
                    ->label('Criteria')
                    ->options(function () {
                        $campusId = filament()->getTenant()?->id;
                        return RatingCriteria::where('campus_id', $campusId)
                            ->where('is_active', true)
                            ->orderBy('order')
                            ->pluck('name', 'id');
                    })
                    ->required()
                    ->searchable(),

                Forms\Components\Select::make('score')
                    ->options([
                        1 => '1 - Poor',
                        2 => '2 - Fair',
                        3 => '3 - Good',
                        4 => '4 - Very Good',
                        5 => '5 - Excellent',
                    ])
                    ->required(),

                Forms\Components\Textarea::make('comment')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('user.student_id')
                    ->label('Evaluator ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Evaluator Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ratingCriteria.name')
                    ->label('Criteria')
                    ->sortable(),

                Tables\Columns\TextColumn::make('score')
                    ->badge()
                    ->color(fn(int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state >= 3 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('comment')
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('rating_criteria_id')
                    ->label('Criteria')
                    ->relationship('ratingCriteria', 'name'),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
