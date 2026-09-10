<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function build()
    {
        // The template is built from Markdown mail components (@component('mail::message')).
        // Those resolve only through Markdown::render(), which is what registers the `mail`
        // view namespace and converts the Markdown body to HTML. Rendering it with ->view()
        // instead threw "No hint path defined for [mail]" on every receipt.
        return $this->subject('Your Payment Receipt')
            ->markdown('emails.payment_receipt')
            ->with(['transaction' => $this->transaction]);
    }
}
