<?php

namespace Elazaroo\PulseBoosted\Issues;

use Elazaroo\PulseBoosted\Events\IssueOpened;
use Elazaroo\PulseBoosted\Events\IssueRegressed;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Recorders\Issues;
use Elazaroo\PulseBoosted\Support\People;
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
        protected People $people,
    ) {
        //
    }

    public function __invoke(IssueOpened|IssueRegressed $event): void
    {
        $recipients = $this->recipients();

        // Whoever is responsible for an issue hears about it coming back,
        // whether or not they are on the list.
        if ($event instanceof IssueRegressed && ($event->issue->assignee ?? null) !== null && $this->config->get('pulse-boosted.issues.notify.assignee', true)) {
            $email = $this->people->one($event->issue->assignee)?->email;

            if ($email !== null && ! in_array($email, $recipients, true)) {
                $recipients[] = $email;
            }
        }

        if ($recipients === []) {
            return;
        }

        if ($event instanceof IssueRegressed && ! $this->config->get('pulse-boosted.issues.notify.regressions', true)) {
            return;
        }

        if (($event->issue->kind ?? null) === 'log' && ! self::severeEnough((string) ($event->issue->level ?? ''), $this->config)) {
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
     * Whether a log issue's level is worth telling anyone about, by email or
     * any other way.
     */
    public static function severeEnough(string $level, Repository $config): bool
    {
        $levels = Issues::LEVELS;

        $at = array_search($level, $levels, true);
        $limit = array_search((string) $config->get('pulse-boosted.issues.notify.log_level', 'error'), $levels, true);

        return $at !== false && $limit !== false && $at <= $limit;
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
