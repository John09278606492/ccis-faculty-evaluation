<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The default roles: super_admin gets everything, instructors run their classes,
 * students take evaluations and assessments. Safe to re-run: it only adds missing
 * permissions to instructor and student, so changes made in the Roles page stay.
 */
class RolesSeeder extends Seeder
{
    public const INSTRUCTOR = [
        'view_any_course::offering', 'view_course::offering', 'update_course::offering',
        'view_any_quiz', 'view_quiz', 'create_quiz', 'update_quiz', 'delete_quiz', 'replicate_quiz',
        'page_QuizBuilder', 'page_ClassRecords', 'page_MyEvaluationResults', 'page_MyProfilePage',
        'widget_InstructorEvaluationSummaryWidget',
    ];

    public const STUDENT = [
        'page_MyEvaluations', 'page_MyQuizzes', 'page_TakeQuiz', 'page_MyProfilePage',
        'widget_StudentPendingEvaluationsWidget',
    ];

    public function run(): void
    {
        // Permissions only: policies are committed and must not be regenerated.
        Artisan::call('shield:generate', ['--all' => true, '--option' => 'permissions', '--panel' => 'admin', '--minimal' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super_admin', 'web')->syncPermissions(Permission::where('guard_name', 'web')->get());

        foreach (['instructor' => self::INSTRUCTOR, 'student' => self::STUDENT] as $name => $permissions) {
            Role::findOrCreate($name, 'web')->givePermissionTo(
                collect($permissions)->map(fn (string $permission) => Permission::findOrCreate($permission, 'web'))
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
