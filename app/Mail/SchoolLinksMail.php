<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SchoolLinksMail extends Mailable
{
    use Queueable, SerializesModels;

    public School $school;

    public array $links;

    /**
     * Create a new message instance.
     */
    public function __construct(School $school, array $links)
    {
        $this->school = $school;
        $this->links = $links;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this
            ->subject('Your School Payment Portal Links')
            ->view('emails.school_links')
            ->with([
                'school' => $this->school,
                'links' => $this->links,
            ]);
    }
}
