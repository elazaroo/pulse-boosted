<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Support\DashboardUrl;
use Elazaroo\PulseBoosted\Support\Location;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use stdClass;

/**
 * The email for a new or returning issue.
 *
 * @internal
 */
class IssueMail extends Mailable
{
    public function __construct(
        public readonly stdClass $issue,
        public readonly bool $regressed = false,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        $prefix = $this->regressed ? '[Regressed] ' : '';

        return new Envelope(
            subject: Str::limit($prefix.class_basename($this->issue->class).': '.($this->issue->message ?: 'no message'), 150),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'pulse-boosted::mail.issue',
            with: [
                'issue' => $this->issue,
                'regressed' => $this->regressed,
                'location' => Location::relative($this->issue->file, $this->issue->line),
                'url' => $this->url(),
                'app' => (string) config('app.name'),
                'environment' => (string) config('app.env'),
            ],
        );
    }

    /**
     * A link that opens the dashboard with this issue's panel already open.
     */
    protected function url(): string
    {
        return DashboardUrl::to(['issue' => $this->issue->fingerprint], 'errors');
    }
}
