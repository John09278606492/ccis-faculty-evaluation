<?php

namespace App\Services;

use App\Models\User;
use App\Settings\EmailReminderSetting;
use Illuminate\Support\Str;

/**
 * School email for a student: the address already on file when it is on the school domain,
 * otherwise built from the name, e.g. "Niño Carlo" + "Dela Cruz" -> ninocarlo.delacruz@bisu.edu.ph.
 */
class StudentEmailAddress
{
    public function for(User $student): ?string
    {
        $settings = app(EmailReminderSetting::class);
        $domain = mb_strtolower(trim($settings->student_domain, " @\t"));
        if ($domain === '') {
            return null;
        }
        $stored = mb_strtolower(trim((string) $student->email));
        if (str_ends_with($stored, '@'.$domain) && filter_var($stored, FILTER_VALIDATE_EMAIL)) {
            return $stored;
        }

        return $this->build($student->first_name, $student->last_name, $domain, $settings->address_format);
    }

    public function build(?string $first, ?string $last, string $domain, string $format = 'first.last'): ?string
    {
        $first = $this->part($first);
        $last = $this->part($last);
        if ($first === '' || $last === '') {
            return null;
        }
        $address = $first.($format === 'firstlast' ? '' : '.').$last.'@'.$domain;

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    /** ñ -> n, é -> e, then letters and digits only ("Ag-Ag" -> agag, "Ma." -> ma). */
    public function part(?string $value): string
    {
        $value = strtr((string) $value, ['ñ' => 'n', 'Ñ' => 'N']);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }
}
