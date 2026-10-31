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

    public function isPlaceholder(?string $email): bool
    {
        $email = trim((string) $email);

        return $email === '' || str_ends_with(mb_strtolower($email), '.invalid');
    }

    /** True when the address is what this system would build from these names (either format). */
    public function isAutomatic(?string $email, ?string $first, ?string $last): bool
    {
        $domain = $this->domain();
        $email = mb_strtolower(trim((string) $email));

        return $domain !== null && $email !== '' && in_array($email, [$this->build($first, $last, $domain, 'first.last'), $this->build($first, $last, $domain, 'firstlast')], true);
    }

    private function domain(): ?string
    {
        try {
            $domain = mb_strtolower(trim(app(EmailReminderSetting::class)->student_domain, " @\t"));
        } catch (\Throwable) {
            return null; // settings not migrated yet
        }

        return $domain !== '' ? $domain : null;
    }

    private function format(): string
    {
        try {
            return app(EmailReminderSetting::class)->address_format;
        } catch (\Throwable) {
            return 'first.last';
        }
    }

    private function taken(string $email, ?int $exceptId): bool
    {
        return User::where('email', $email)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    /**
     * Called while a student record is saved: replaces a blank/placeholder address with the school address,
     * and follows name corrections when the old address was the automatic one. A real address typed by staff is kept.
     */
    public function applyTo(User $student): void
    {
        $domain = $this->domain();
        if (! $domain || ! $student->first_name || ! $student->last_name) {
            return;
        }
        $wasAutomatic = $student->exists && $student->isDirty(['first_name', 'last_name']) && ! $student->isDirty('email')
            && $this->isAutomatic($student->getOriginal('email'), $student->getOriginal('first_name'), $student->getOriginal('last_name'));
        if (! $this->isPlaceholder($student->email) && ! $wasAutomatic) {
            return;
        }
        $address = $this->build($student->first_name, $student->last_name, $domain, $this->format());
        if ($address && ! $this->taken($address, $student->id)) {
            $student->email = $address;
        }
    }

    /**
     * Fill school addresses for every student still on a placeholder (and, with $rebuild, re-make automatic ones
     * after the format or domain changed). Records are updated without touching anything else.
     *
     * @return array{filled: int, unchanged: int, duplicate: int, no_name: int, duplicates: array<int, string>}
     */
    public function backfill(bool $rebuild = false, bool $dryRun = false): array
    {
        $result = ['filled' => 0, 'unchanged' => 0, 'duplicate' => 0, 'no_name' => 0, 'duplicates' => []];
        $domain = $this->domain();
        if (! $domain) {
            return $result;
        }
        $format = $this->format();
        $used = User::pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [mb_strtolower($email) => $id])->all();
        User::where('is_student', true)->orderBy('id')->select(['id', 'email', 'first_name', 'last_name', 'name', 'student_id'])
            ->chunkById(500, function ($students) use (&$result, &$used, $domain, $format, $rebuild, $dryRun) {
                foreach ($students as $student) {
                    $automatic = $this->isAutomatic($student->email, $student->first_name, $student->last_name);
                    if (! $this->isPlaceholder($student->email) && ! ($rebuild && $automatic)) {
                        $result['unchanged']++;

                        continue;
                    }
                    $address = $this->build($student->first_name, $student->last_name, $domain, $format);
                    if (! $address) {
                        $result['no_name']++;

                        continue;
                    }
                    if (mb_strtolower((string) $student->email) === $address) {
                        $result['unchanged']++;

                        continue;
                    }
                    if (isset($used[$address]) && $used[$address] !== $student->id) {
                        $result['duplicate']++;
                        $result['duplicates'][] = $student->student_id.' '.$student->name.' ('.$address.')';

                        continue;
                    }
                    if (! $dryRun) {
                        User::whereKey($student->id)->update(['email' => $address]);
                    }
                    unset($used[mb_strtolower((string) $student->email)]);
                    $used[$address] = $student->id;
                    $result['filled']++;
                }
            });

        return $result;
    }

    /** ñ -> n, é -> e, then letters and digits only ("Ag-Ag" -> agag, "Ma." -> ma). */
    public function part(?string $value): string
    {
        $value = strtr((string) $value, ['ñ' => 'n', 'Ñ' => 'N']);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }
}
