<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

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
            ->with([
                'transaction' => $this->transaction,
                // Signed, never a bare id. The signature is the reader's proof of
                // access: PaymentController::authorizeReceipt accepts a valid
                // signature, so the link opens from an inbox with no session, while
                // /payment/receipt/{id} on its own still 404s. Transaction ids are
                // sequential, so an unsigned link would be an enumeration handle
                // over every payer's name, email, amount and reference.
                //
                // Built here rather than in the view because this runs on the queue
                // worker with no incoming request: the host comes from APP_URL, which
                // is why the worker must carry the same APP_URL as the web service.
                'receiptUrl' => URL::signedRoute(
                    'payment.receipt',
                    ['transaction' => $this->transaction->id]
                ),
            ]);
    }
}
