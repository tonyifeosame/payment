<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SchoolPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public School $school;

    public string $resetLink;

    /**
     * Create a new message instance.
     */
    public function __construct(School $school, string $resetLink)
    {
        $this->school = $school;
        $this->resetLink = $resetLink;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this
            ->subject('Reset Your School Admin Password')
            ->view('emails.school_password_reset');
    }
}
