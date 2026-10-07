<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * M1: sent to the email address that was on file when the school's name (the
 * login identifier) or email (the password-reset identifier) changes, so an
 * unauthorised change reaches the people who can still act on it.
 */
class SchoolIdentityChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{name: ?string, email: ?string}  $previous
     */
    public function __construct(public School $school, public array $previous) {}

    public function build()
    {
        return $this
            ->subject('Your school sign-in details were changed')
            ->view('emails.school_identity_changed')
            ->with(['school' => $this->school, 'previous' => $this->previous]);
    }
}
