<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('EmailReminderSetting.sender_email', null);
        $this->migrator->add('EmailReminderSetting.sender_name', 'BISU Balilihan Faculty Evaluation');
        $this->migrator->addEncrypted('EmailReminderSetting.smtp_password', null);
        $this->migrator->add('EmailReminderSetting.smtp_host', 'smtp.gmail.com');
        $this->migrator->add('EmailReminderSetting.smtp_port', 587);
        $this->migrator->add('EmailReminderSetting.student_domain', 'bisu.edu.ph');
        $this->migrator->add('EmailReminderSetting.address_format', 'first.last');
    }
};
