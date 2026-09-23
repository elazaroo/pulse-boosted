<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Traces\Tracer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Factory as Mailer;

/**
 * Emails whoever is configured when an issue opens or comes back.
 *
 * This goes through the application's own mailer, so it needs nothing the
 * application does not already have. For anything else — Slack, a pager —
 * listen for IssueOpened and IssueRegressed yourself.
 *
 * @internal
 */
class SendIssueMail
{
    public function __construct(
        protected Repository $config,
        protected Mailer $mail,
        protected Pulse $pulse,
        protected Tracer $tracer,
    ) {
        //
    }

    public function __invoke(IssueOpened|IssueRegressed $event): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        if ($event instanceof IssueRegressed && ! $this->config->get('pulse-boosted.issues.notify.regressions', true)) {
            return;
        }

        $mail = new IssueMail($event->issue, regressed: $event instanceof IssueRegressed);

        if (($mailer = $this->config->get('pulse-boosted.issues.notify.mailer')) !== null) {
            $mail->mailer((string) $mailer);
        }

        // Sending mail is itself something the recorders would note; it is
        // the dashboard's own work, not the application's. And a mailer that
        // throws must not become an issue that sends a mail that throws.
        $this->pulse->rescue(fn () => $this->tracer->ignore(fn () => $this->pulse->ignore(
            fn () => $this->mail->mailer($mail->mailer)->to($recipients)->send($mail)
        )));
    }

    /**
     * @return list<string>
     */
    protected function recipients(): array
    {
        $to = $this->config->get('pulse-boosted.issues.notify.mail', []);

        if (is_string($to)) {
            $to = explode(',', $to);
        }

        return array_values(array_filter(array_map('trim', (array) $to)));
    }
}
