<?php

namespace App\Mail;

use App\Models\EvaluationReminder;
use App\Services\EvaluationReminders;
use App\Settings\EmailReminderSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EvaluationReminderMail extends Mailable
{
    use Queueable;

    public function __construct(public EvaluationReminder $reminder) {}

    public function envelope(): Envelope
    {
        $settings = app(EmailReminderSetting::class);
        $sender = $this->reminder->sender;
        $replyTo = $sender?->email && filter_var($sender->email, FILTER_VALIDATE_EMAIL) && ! str_ends_with($sender->email, '.invalid')
            ? [new Address($sender->email, $sender->name)] : [];

        return new Envelope(from: new Address((string) $settings->sender_email, $settings->sender_name), replyTo: $replyTo, subject: $this->reminder->subject);
    }

    public function content(): Content
    {
        $student = $this->reminder->student;
        $campus = $this->reminder->campus;

        return new Content(view: 'emails.evaluation-reminder', with: [
            'firstName' => $student->first_name ?: $student->name,
            'senderLine' => $this->reminder->sender && $campus ? app(EvaluationReminders::class)->senderLine($this->reminder->sender, $campus) : 'Your college is asking you to complete your faculty evaluation.',
            'body' => $this->reminder->message,
            'remaining' => $this->reminder->remaining,
            'headline' => $this->reminder->subject,
            'link' => route('evaluation.entry'),
            'studentId' => $student->student_id,
            'campusName' => $campus?->name,
        ]);
    }
}
