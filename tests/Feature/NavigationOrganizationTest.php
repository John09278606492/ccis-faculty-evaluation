<?php

use App\Filament\Pages\AccessAssignments;
use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Pages\DepartmentReport;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\IndividualEvaluationCopies;
use App\Filament\Pages\ManageSetting;
use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Pages\SupervisorEvaluations;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\AcademicStudentResource;
use App\Filament\Resources\CampusResource;
use App\Filament\Resources\CourseResource;
use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SchoolYearResource;
use App\Filament\Resources\SectionResource;
use App\Filament\Resources\UserResource;

it('keeps personal staff work together in My Work', function () {
    expect(SupervisorEvaluations::getNavigationGroup())->toBe('My Work')
        ->and(MyEvaluationResults::getNavigationGroup())->toBe('My Work');
});

it('lists administration pages in one group and a fixed order', function () {
    $items = [UserResource::class, RoleResource::class, AccessAssignments::class, CampusResource::class, ManageSetting::class, BackgroundProcessing::class, SystemHealth::class];

    foreach ($items as $item) {
        expect($item::getNavigationGroup())->toBe('Administration');
    }
    expect(array_map(fn ($item) => $item::getNavigationSort(), $items))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(ManageSetting::getNavigationLabel())->toBe('System Settings')
        ->and(UserResource::getPluralModelLabel())->toBe('Staff Accounts');
});

it('orders academic records in setup order', function () {
    $items = [SchoolYearResource::class, DepartmentResource::class, CourseResource::class, AcademicStudentResource::class, SectionResource::class];

    expect(array_map(fn ($item) => $item::getNavigationSort(), $items))->toBe([1, 2, 3, 4, 5]);
});

it('registers every navigation group the panel uses', function () {
    $registered = collect(filament()->getPanel('admin')->getNavigationGroups())->map(fn ($group) => $group->getLabel())->all();

    foreach ([SupervisorEvaluations::class, RoleResource::class, UserResource::class, SchoolYearResource::class] as $item) {
        expect($registered)->toContain($item::getNavigationGroup());
    }
});

it('keeps every evaluation report in the Evaluation Reports group, faculty results first', function () {
    expect(EvaluationResults::getNavigationGroup())->toBe('Evaluation Reports')
        ->and(EvaluationResults::getNavigationSort())->toBe(1)
        ->and(IndividualEvaluationCopies::getNavigationGroup())->toBe('Evaluation Reports')
        ->and(DepartmentReport::getNavigationGroup())->toBe('Evaluation Reports');
});
