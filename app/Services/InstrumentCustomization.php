<?php

namespace App\Services;

use App\Models\Form;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InstrumentCustomization
{
    public static function allowed(User $actor, Form $form): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }
        $campus = filament()->getTenant() ?? $form->campuses()->whereIn('campuses.id', $actor->campuses()->pluck('campuses.id'))->first();
        if (! $campus || ! $actor->canAccessTenant($campus)) {
            return false;
        }
        $ids = app(AssignedDataAccess::class)->departmentIds($actor, $campus);
        if ($ids === null) {
            return true;
        }
        $departments = DB::table('department_form')->where('form_id', $form->id)->pluck('department_id')->all();

        return $departments !== [] && array_diff($departments, $ids) === [];
    }
}
