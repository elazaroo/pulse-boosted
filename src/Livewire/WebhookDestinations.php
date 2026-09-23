<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Notify\Destinations;
use Elazaroo\PulseBoosted\Notify\Webhooks;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The webhooks, as a list to add to, change, test and remove.
 *
 * A webhook's address is never sent back to the browser once saved: it can
 * be replaced with a new one, but not read, so the page cannot leak it.
 *
 * @internal
 */
class WebhookDestinations extends Component
{
    public int|string|null $cols = 'full';

    public bool $adding = false;

    /**
     * The webhook being added.
     *
     * @var array{name: string, type: string, url: string, secret: string, chat_id: string, events: list<string>}
     */
    public array $form = ['name' => '', 'type' => '', 'url' => '', 'secret' => '', 'chat_id' => '', 'events' => []];

    /**
     * Whether the type was picked by hand, rather than worked out from the
     * address.
     */
    public bool $typeChosen = false;

    public ?int $editing = null;

    /**
     * Changes to the webhook being edited. The address and secret start empty
     * and are only replaced when something is typed.
     *
     * @var array{name: string, events: list<string>, url: string, secret: string, chat_id: string, clear_secret: bool}
     */
    public array $edit = ['name' => '', 'events' => [], 'url' => '', 'secret' => '', 'chat_id' => '', 'clear_secret' => false];

    /**
     * The last test of each webhook, by id.
     *
     * @var array<int, array{id: int, name: string, ok: bool, status: ?int, error: ?string}>
     */
    public array $results = [];

    public function startAdding(): void
    {
        $this->adding = true;
        $this->editing = null;
        $this->typeChosen = false;
        $this->form = ['name' => '', 'type' => '', 'url' => '', 'secret' => '', 'chat_id' => '', 'events' => Webhooks::EVENTS];
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->editing = null;
        $this->resetErrorBag();
    }

    /**
     * Work out the service from the address, unless it was picked by hand.
     */
    public function updatedFormUrl(string $url): void
    {
        if (! $this->typeChosen && ($type = Destinations::detect($url)) !== null) {
            $this->form['type'] = $type;
        }
    }

    public function updatedFormType(): void
    {
        $this->typeChosen = true;
    }

    public function add(Destinations $destinations, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $this->form['type'] = $this->form['type'] ?: (Destinations::detect($this->form['url']) ?? 'json');

        $this->validate([
            'form.type' => ['required', Rule::in(array_keys(Destinations::TYPES))],
            'form.url' => ['required', 'string', 'max:2000', $this->addressRule($this->form['type'])],
            'form.name' => ['nullable', 'string', 'max:120'],
            'form.secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'form.chat_id' => [Rule::requiredIf($this->form['type'] === 'telegram'), 'nullable', 'string', 'max:64'],
            'form.events' => ['required', 'array', 'min:1'],
            'form.events.*' => [Rule::in(Webhooks::EVENTS)],
        ], [
            'form.chat_id.required' => 'Telegram needs the chat to post to.',
            'form.events.required' => 'Pick at least one event.',
        ]);

        $id = $destinations->create([
            'name' => trim($this->form['name']) ?: Destinations::TYPES[$this->form['type']],
            'type' => $this->form['type'],
            'url' => $this->form['url'],
            'secret' => $this->form['type'] === 'json' ? ($this->form['secret'] ?: null) : null,
            'chat_id' => $this->form['type'] === 'telegram' ? $this->form['chat_id'] : null,
            'events' => $this->eventsFor($this->form['events']),
        ], $this->me());

        $this->adding = false;
        $this->form = ['name' => '', 'type' => '', 'url' => '', 'secret' => '', 'chat_id' => '', 'events' => []];
        unset($this->results[$id]);
    }

    public function startEditing(int $id, Destinations $destinations): void
    {
        $webhook = $destinations->all()->firstWhere('id', $id);

        if ($webhook === null) {
            return;
        }

        $this->adding = false;
        $this->editing = $id;
        $this->edit = [
            'name' => (string) $webhook->name,
            'events' => $webhook->events ?? Webhooks::EVENTS,
            'url' => '',
            'secret' => '',
            'chat_id' => '',
            'clear_secret' => false,
        ];
        $this->resetErrorBag();
    }

    public function saveEdit(Destinations $destinations, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $webhook = $destinations->all()->firstWhere('id', $this->editing);

        if ($webhook === null) {
            $this->cancel();

            return;
        }

        $this->validate([
            'edit.name' => ['required', 'string', 'max:120'],
            'edit.url' => ['nullable', 'string', 'max:2000', $this->addressRule((string) $webhook->type)],
            'edit.secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'edit.chat_id' => ['nullable', 'string', 'max:64'],
            'edit.events' => ['required', 'array', 'min:1'],
            'edit.events.*' => [Rule::in(Webhooks::EVENTS)],
        ], ['edit.events.required' => 'Pick at least one event.']);

        $changes = [
            'name' => $this->edit['name'],
            'events' => $this->eventsFor($this->edit['events']),
            'url' => trim($this->edit['url']),
            'chat_id' => trim($this->edit['chat_id']),
        ];

        if ($webhook->type === 'json' && ($this->edit['clear_secret'] || $this->edit['secret'] !== '')) {
            $changes['secret'] = $this->edit['clear_secret'] ? null : $this->edit['secret'];
        }

        $destinations->update((int) $webhook->id, $changes);

        if ($changes['url'] !== '') {
            unset($this->results[(int) $webhook->id]);
        }

        $this->editing = null;
    }

    public function toggle(int $id, Destinations $destinations, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $webhook = $destinations->all()->firstWhere('id', $id);

        if ($webhook !== null) {
            $destinations->update($id, ['enabled' => ! $webhook->enabled]);
        }
    }

    public function delete(int $id, Destinations $destinations, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $destinations->delete($id);

        unset($this->results[$id]);

        if ($this->editing === $id) {
            $this->editing = null;
        }
    }

    public function test(int $id, Webhooks $webhooks, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        foreach ($webhooks->test($id, $this->me()) as $result) {
            $this->results[$result['id']] = $result;
        }
    }

    public function render(Destinations $destinations, QueueActions $actions): Renderable
    {
        return View::make('pulse-boosted::livewire.webhook-destinations', [
            'webhooks' => $destinations->all(),
            'types' => Destinations::TYPES,
            'events' => Webhooks::EVENTS,
            'canManage' => $actions->allowed(),
            'hints' => [
                'slack' => 'https://hooks.slack.com/services/…',
                'discord' => 'https://discord.com/api/webhooks/…',
                'teams' => 'https://…logic.azure.com/workflows/… or https://….webhook.office.com/…',
                'google_chat' => 'https://chat.googleapis.com/v1/spaces/…/messages?key=…&token=…',
                'mattermost' => 'https://mattermost.example.com/hooks/…',
                'telegram' => 'https://api.telegram.org/bot<token>/sendMessage',
                'json' => 'https://ops.example.com/hooks/pulse',
            ],
        ]);
    }

    /**
     * An address that is https — or plain http for a URL of your own — and
     * that does not look like it belongs to a different service than the one
     * picked.
     */
    protected function addressRule(string $type): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($type) {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            if (filter_var($value, FILTER_VALIDATE_URL) === false || ! preg_match($type === 'json' ? '#^https?://#i' : '#^https://#i', $value)) {
                $fail($type === 'json' ? 'That is not an http or https address.' : 'That is not an https address.');

                return;
            }

            $detected = Destinations::detect($value);

            if ($detected !== null && $detected !== $type && ! ($type === 'json')) {
                $fail('That looks like a '.Destinations::TYPES[$detected].' address, not '.Destinations::TYPES[$type].'.');
            }
        };
    }

    /**
     * Null for every event, so one added later is sent too.
     *
     * @param  list<string>  $events
     * @return list<string>|null
     */
    protected function eventsFor(array $events): ?array
    {
        $events = array_values(array_intersect(Webhooks::EVENTS, $events));

        return count($events) === count(Webhooks::EVENTS) ? null : $events;
    }

    protected function me(): ?string
    {
        $id = app(Pulse::class)->resolveAuthenticatedUserId();

        return $id === null ? null : Str::limit((string) $id, 190, '');
    }
}
