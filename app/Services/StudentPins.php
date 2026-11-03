<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Students sign in with their ID number and a short PIN. PINs are stored hashed in the
 * password column, so they are shown only once: when an instructor or admin issues them.
 */
class StudentPins
{
    public const LENGTH = 6;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<int, User>  $students
     * @return Collection<int, array{student: User, pin: string}>
     */
    public static function issue(Collection $students): Collection
    {
        return $students->map(function (User $student) {
            $pin = self::generate();
            $student->forceFill([
                'password' => Hash::make($pin),
                'pin_issued_at' => now(),
                'must_change_password' => false,
            ])->save();

            return ['student' => $student, 'pin' => $pin];
        })->values();
    }

    /**
     * @param  Collection<int, array{student: User, pin: string}>  $issued
     */
    public static function download(Collection $issued, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($issued) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Student ID', 'Name', 'PIN']);
            foreach ($issued->sortBy(fn ($row) => $row['student']->last_name ?? $row['student']->name) as $row) {
                fputcsv($out, [$row['student']->student_id, $row['student']->full_name, $row['pin']]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
