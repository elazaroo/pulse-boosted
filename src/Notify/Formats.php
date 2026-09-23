<?php

namespace Elazaroo\PulseBoosted\Notify;

use Illuminate\Support\Str;

/**
 * The same event, in the shape each service shows best: Slack's blocks,
 * Discord's embeds, a Teams adaptive card, a Google Chat or Telegram message,
 * or the event itself as JSON for anything else.
 *
 * @internal
 */
class Formats
{
    /**
     * @param  array<string, mixed>  $payload  event, summary, app, environment, url, sent_at and data.
     * @return array<string, mixed>
     */
    public static function body(string $type, array $payload, ?string $chatId = null): array
    {
        return match ($type) {
            'slack', 'mattermost' => self::slack($payload),
            'discord' => self::discord($payload),
            'teams' => self::teams($payload),
            'google_chat' => self::googleChat($payload),
            'telegram' => self::telegram($payload, $chatId),
            default => $payload,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function slack(array $payload): array
    {
        $escape = fn (string $text) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        $text = $escape($payload['summary']);

        if ($payload['url'] !== null) {
            $text .= "\n<".$payload['url'].'|Open in Pulse Boosted>';
        }

        $fields = self::fields($payload);

        return [
            'text' => $payload['summary'],
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
                ...($fields === [] ? [] : [['type' => 'context', 'elements' => array_map(
                    fn (string $label, string $value) => ['type' => 'mrkdwn', 'text' => "*{$label}:* ".$escape($value)],
                    array_keys($fields),
                    array_values($fields),
                )]]),
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $escape(self::footer($payload))]]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function discord(array $payload): array
    {
        return [
            'username' => 'Pulse Boosted',
            // Nothing in an issue's message gets to ping @everyone.
            'allowed_mentions' => ['parse' => []],
            'embeds' => [array_filter([
                'title' => Str::limit($payload['summary'], 250),
                'url' => $payload['url'],
                'color' => hexdec(ltrim(self::colour($payload['event']), '#')),
                'fields' => array_map(
                    fn (string $label, string $value) => ['name' => $label, 'value' => Str::limit($value, 1000), 'inline' => true],
                    array_keys(self::fields($payload)),
                    array_values(self::fields($payload)),
                ),
                'footer' => ['text' => self::footer($payload)],
                'timestamp' => $payload['sent_at'],
            ], fn ($value) => $value !== null && $value !== [])],
        ];
    }

    /**
     * An adaptive card, which both Teams workflows and the older incoming
     * webhook connectors show.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function teams(array $payload): array
    {
        $facts = array_map(
            fn (string $label, string $value) => ['title' => $label, 'value' => $value],
            array_keys(self::fields($payload)),
            array_values(self::fields($payload)),
        );

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $payload['summary'], 'weight' => 'Bolder', 'wrap' => true, 'color' => self::teamsColour($payload['event'])],
                        ...($facts === [] ? [] : [['type' => 'FactSet', 'facts' => $facts]]),
                        ['type' => 'TextBlock', 'text' => self::footer($payload), 'isSubtle' => true, 'size' => 'Small', 'wrap' => true],
                    ],
                    'actions' => $payload['url'] === null ? [] : [
                        ['type' => 'Action.OpenUrl', 'title' => 'Open in Pulse Boosted', 'url' => $payload['url']],
                    ],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function googleChat(array $payload): array
    {
        $lines = ['*'.$payload['summary'].'*'];

        foreach (self::fields($payload) as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($payload['url'] !== null) {
            $lines[] = '<'.$payload['url'].'|Open in Pulse Boosted>';
        }

        $lines[] = '_'.self::footer($payload).'_';

        return ['text' => implode("\n", $lines)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function telegram(array $payload, ?string $chatId): array
    {
        $escape = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = ['<b>'.$escape($payload['summary']).'</b>'];

        foreach (self::fields($payload) as $label => $value) {
            $lines[] = $escape($label).': '.$escape($value);
        }

        if ($payload['url'] !== null) {
            $lines[] = '<a href="'.$escape($payload['url']).'">Open in Pulse Boosted</a>';
        }

        $lines[] = '<i>'.$escape(self::footer($payload)).'</i>';

        return [
            'chat_id' => $chatId,
            'text' => Str::limit(implode("\n", $lines), 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
    }

    /**
     * The details worth a line of their own.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected static function fields(array $payload): array
    {
        $fields = [];

        foreach ((array) ($payload['data']['fields'] ?? []) as $label => $value) {
            if ($value !== null && $value !== '') {
                $fields[(string) $label] = Str::limit((string) $value, 200);
            }
        }

        return array_slice($fields, 0, 10, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function footer(array $payload): string
    {
        return trim($payload['app'].' · '.$payload['environment'], ' ·');
    }

    /**
     * Red for something wrong, green for something better, blue for the rest.
     */
    public static function colour(string $event): string
    {
        return match ($event) {
            'issue.opened', 'issue.regressed', 'alert.triggered', 'schedule.missed' => '#dc2626',
            'alert.resolved' => '#16a34a',
            default => '#6366f1',
        };
    }

    protected static function teamsColour(string $event): string
    {
        return match (self::colour($event)) {
            '#dc2626' => 'Attention',
            '#16a34a' => 'Good',
            default => 'Accent',
        };
    }
}
