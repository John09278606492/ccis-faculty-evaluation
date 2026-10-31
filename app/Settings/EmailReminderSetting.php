<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/** Who sends evaluation reminder emails (a super admin's mailbox) and how student addresses are built. */
class EmailReminderSetting extends Settings
{
    public ?string $sender_email;

    public string $sender_name;

    public ?string $smtp_password;

    public string $smtp_host;

    public int $smtp_port;

    public string $student_domain;

    /** 'first.last' (juan.delacruz@...) or 'firstlast' (juandelacruz@...). */
    public string $address_format;

    public static function group(): string
    {
        return 'EmailReminderSetting';
    }

    public static function encrypted(): array
    {
        return ['smtp_password'];
    }

    public function ready(): bool
    {
        return filled($this->sender_email) && filled($this->smtp_password) && filled($this->smtp_host);
    }
}
