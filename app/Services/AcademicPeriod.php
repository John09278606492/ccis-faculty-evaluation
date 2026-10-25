<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Semester;
use Illuminate\Validation\ValidationException;

class AcademicPeriod
{
    public static function current(Campus $campus): ?int
    {
        $saved = request()->hasSession() ? session('academic_period.'.$campus->id) : null;
        if ($saved && Semester::whereKey($saved)->whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->exists()) {
            return (int) $saved;
        }

        return Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->where('is_active', true)
            ->orderByDesc('start_date')->value('id');
    }

    public static function validate(Campus $campus, mixed $id): int
    {
        if (! $id || ! Semester::whereKey($id)->whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->exists()) {
            throw ValidationException::withMessages(['semester_id' => 'Choose an academic period in this campus.']);
        }

        return (int) $id;
    }

    public static function select(Campus $campus, int $id): void
    {
        self::validate($campus, $id);
        session()->put('academic_period.'.$campus->id, $id);
    }

    public static function options(Campus $campus): array
    {
        return Semester::whereHas('schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->with('schoolYear')->orderByDesc('start_date')->get()
            ->mapWithKeys(fn ($s) => [$s->id => $s->name.' / '.$s->schoolYear->name])->all();
    }
}
