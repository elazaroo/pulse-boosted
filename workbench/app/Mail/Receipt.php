<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class Receipt extends Mailable
{
    public function __construct(public int $orderId)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your receipt for order #{$this->orderId}");
    }

    public function content(): Content
    {
        return new Content(htmlString: "<p>Thanks for order #{$this->orderId}.</p>");
    }
}
