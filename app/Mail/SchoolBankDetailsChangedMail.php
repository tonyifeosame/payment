<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the school's email whenever its payout bank account is changed, so an
 * unauthorised change is noticed by the people whose money it is.
 */
class SchoolBankDetailsChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public School $school;

    /** @var array{bank: ?string, account_number: ?string, account_name: ?string} */
    public array $previous;

    public function __construct(School $school, array $previous)
    {
        $this->school = $school;
        $this->previous = $previous;
    }

    public function build()
    {
        return $this
            ->subject('Your payout bank account was changed')
            ->view('emails.school_bank_details_changed')
            ->with([
                'school' => $this->school,
                'previous' => $this->previous,
            ]);
    }
}
