<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonInterval;
use Elazaroo\PulseBoosted\Notify\Webhooks;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Settings\Schema;
use Elazaroo\PulseBoosted\Settings\Settings as Store;
use Elazaroo\PulseBoosted\Support\People;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * One group of settings, as a form.
 *
 * Saving writes only what differs from config, so a field set back to what
 * the environment says stops overriding it. Changing anything needs the same
 * gate as the dashboard's other changes; anyone who can see the dashboard can
 * read the page, with secrets masked.
 *
 * @phpstan-import-type Field from Schema
 *
 * @internal
 */
class Settings extends Component
{
    /**
     * Which group this form is for.
     */
    public string $group = 'email';

    public int|string|null $cols = 'full';

    /**
     * The form, keyed by field id.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * The result of the last webhook test.
     *
     * @var list<array{target: string, slack: bool, ok: bool, status: ?int, error: ?string}>|null
     */
    public ?array $tested = null;

    public ?string $saved = null;

    public function mount(Store $settings): void
    {
        $this->load($settings);
    }

    /**
     * Save the group.
     */
    public function save(Store $settings, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $this->validate($this->rules());

        foreach ($this->fields() as $field) {
            $id = Schema::id($field['key']);

            // A secret left blank is a secret kept.
            if (Schema::secret($field) && trim((string) ($this->values[$id] ?? '')) === '') {
                continue;
            }

            $value = $this->toConfig($field, $this->values[$id] ?? null);
            $default = $this->toConfig($field, $this->fromConfig($field, $settings->default($field['key'])));

            $value == $default
                ? $settings->forget($field['key'])
                : $settings->set($field['key'], $value, $this->me());
        }

        $this->load($settings);
        $this->saved = now()->toTimeString();
    }

    /**
     * Go back to what config says for one field.
     */
    public function useConfig(string $key, Store $settings, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        if (Schema::field($key) !== null) {
            $settings->forget($key);
        }

        $this->load($settings);
    }

    /**
     * Remove a secret altogether, rather than leave it as config has it.
     */
    public function clearSecret(string $key, Store $settings, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $field = Schema::field($key);

        if ($field !== null && Schema::secret($field)) {
            $settings->set($key, $field['type'] === 'secrets' ? [] : null, $this->me());
        }

        $this->load($settings);
    }

    /**
     * A random signing secret, for the form to save.
     */
    public function generateSecret(string $key): void
    {
        $this->values[Schema::id($key)] = Str::random(40);
    }

    /**
     * Add a row to a list of thresholds or rules.
     */
    public function addRow(string $key): void
    {
        $field = Schema::field($key);
        $id = Schema::id($key);

        $this->values[$id] = [
            ...($this->values[$id] ?? []),
            $field !== null && $field['type'] === 'rules'
                ? ['name' => '', 'metric' => 'error_rate', 'threshold' => '', 'comparison' => 'above', 'window' => '5 minutes', 'options' => '', 'description' => '']
                : ['type' => 'request', 'pattern' => '*', 'ms' => ''],
        ];
    }

    public function removeRow(string $key, int $index): void
    {
        $id = Schema::id($key);
        $rows = $this->values[$id] ?? [];

        unset($rows[$index]);

        $this->values[$id] = array_values($rows);
    }

    /**
     * Send a test to every webhook as saved.
     */
    public function testWebhooks(Webhooks $webhooks, QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);

        $this->tested = $webhooks->test($this->me());
    }

    public function render(Store $settings, QueueActions $actions, People $people): Renderable
    {
        $fields = $this->fields();

        return View::make('pulse-boosted::livewire.settings', [
            'title' => Schema::GROUPS[$this->group][0] ?? Str::headline($this->group),
            'description' => Schema::GROUPS[$this->group][1] ?? null,
            'fields' => $fields,
            'canManage' => $actions->allowed(),
            'changes' => collect($fields)->mapWithKeys(fn (array $field) => [$field['key'] => $settings->change($field['key'])])->all(),
            'people' => $people->resolve(collect($fields)->map(fn (array $field) => $settings->change($field['key'])['updated_by'] ?? null)),
            'secrets' => $this->secrets($fields, $settings),
            'arrays' => collect($fields)->filter(fn (array $field) => $field['type'] === 'milliseconds' && is_array($settings->value($field['key'])))->keys()->all(),
        ]);
    }

    /**
     * What can be shown of each secret in the group.
     *
     * @param  list<Field>  $fields
     * @return array<string, list<string>>
     */
    protected function secrets(array $fields, Store $settings): array
    {
        $secrets = [];

        foreach ($fields as $field) {
            if (Schema::secret($field)) {
                $secrets[$field['key']] = $this->masked($field, $settings->value($field['key']));
            }
        }

        return $secrets;
    }

    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return Schema::group($this->group);
    }

    protected function load(Store $settings): void
    {
        $this->values = [];

        foreach ($this->fields() as $field) {
            // Secrets are never sent to the browser; the form starts blank.
            $this->values[Schema::id($field['key'])] = Schema::secret($field)
                ? ''
                : $this->fromConfig($field, $settings->value($field['key']));
        }

        $this->resetErrorBag();
    }

    /**
     * What config holds, as the form edits it.
     *
     * @param  Field  $field
     */
    protected function fromConfig(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'bool' => (bool) $value,
            'emails', 'urls', 'ids' => implode("\n", $this->list($value)),
            'secrets', 'secret' => '',
            'select' => $value === null ? '' : (string) $value,
            'rate' => $value === null ? '' : (string) (float) $value,
            'milliseconds' => $value === null || $value === '' || is_array($value) ? '' : (string) (int) $value,
            'text' => (string) ($value ?? ''),
            'events' => is_array($value) ? array_values(array_intersect(Schema::events(), $value)) : Schema::events(),
            'thresholds' => $this->thresholdRows(is_array($value) ? $value : []),
            'rules' => array_map(fn ($rule) => [
                'name' => (string) ($rule['name'] ?? ''),
                'metric' => (string) ($rule['metric'] ?? 'error_rate'),
                'threshold' => (string) ($rule['threshold'] ?? ''),
                'comparison' => (string) ($rule['comparison'] ?? 'above'),
                'window' => (string) ($rule['window'] ?? '5 minutes'),
                'options' => collect((array) ($rule['options'] ?? []))->map(fn ($v, $k) => "{$k}=".(is_bool($v) ? ($v ? 'true' : 'false') : $v))->implode(', '),
                'description' => (string) ($rule['description'] ?? ''),
            ], array_values(array_filter(is_array($value) ? $value : [], 'is_array'))),
        };
    }

    /**
     * What the form holds, as config expects it.
     *
     * @param  Field  $field
     */
    protected function toConfig(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'bool' => (bool) $value,
            'emails', 'urls', 'ids', 'secrets' => $this->list($value),
            'secret' => trim((string) $value) === '' ? null : trim((string) $value),
            'select' => $value === '' || $value === null ? ($field['key'] === 'issues.auto_resolve_after' ? null : '') : (string) $value,
            'rate' => $value === '' || $value === null ? null : (float) $value,
            'milliseconds' => $value === '' || $value === null ? null : (int) $value,
            'text' => trim((string) $value) === '' ? null : trim((string) $value),
            'events' => count(array_intersect(Schema::events(), (array) $value)) === count(Schema::events()) ? null : array_values(array_intersect(Schema::events(), (array) $value)),
            'thresholds' => $this->thresholdConfig((array) $value),
            'rules' => array_map(fn (array $row) => array_filter([
                'name' => trim((string) $row['name']),
                'metric' => (string) $row['metric'],
                'threshold' => (float) $row['threshold'],
                'comparison' => (string) $row['comparison'],
                'window' => trim((string) $row['window']),
                'options' => $this->options((string) ($row['options'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
            ], fn ($v) => $v !== '' && $v !== []), array_values((array) $value)),
        };
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $path = 'values.'.Schema::id($field['key']);

            match ($field['type']) {
                'bool' => $rules[$path] = ['boolean'],
                'emails' => $rules[$path] = ['nullable', 'string', function ($attribute, $value, $fail) {
                    foreach ($this->list($value) as $email) {
                        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                            $fail("“{$email}” is not an email address.");
                        }
                    }
                }],
                'urls', 'secrets' => $rules[$path] = ['nullable', 'string', function ($attribute, $value, $fail) {
                    foreach ($this->list($value) as $url) {
                        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $url)) {
                            $fail('“'.Str::limit($url, 40).'” is not an http or https address.');
                        }
                    }
                }],
                'ids', 'text' => $rules[$path] = ['nullable', 'string', 'max:2000'],
                'secret' => $rules[$path] = ['nullable', 'string', 'min:16', 'max:255'],
                'select' => $rules[$path] = ['nullable', 'in:'.implode(',', array_map(fn ($o) => str_replace(',', '\\,', (string) $o), array_keys($field['options'] ?? [])))],
                'rate' => $rules[$path] = ['required', 'numeric', 'between:0,1'],
                'milliseconds' => $rules[$path] = ['nullable', 'integer', 'min:1', 'max:3600000'],
                'events' => $rules[$path] = ['array', 'min:1'],
                'thresholds' => $rules += [
                    "{$path}.*.type" => ['required', 'in:'.implode(',', array_keys(Schema::thresholdTypes()))],
                    "{$path}.*.pattern" => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                        if (strlen($value) > 2 && $value[0] === $value[strlen($value) - 1] && in_array($value[0], ['#', '/', '~'], true) && @preg_match($value, '') === false) {
                            $fail("“{$value}” is not a valid pattern.");
                        }
                    }],
                    "{$path}.*.ms" => ['required', 'integer', 'min:1', 'max:3600000'],
                ],
                'rules' => $rules += [
                    "{$path}.*.name" => ['required', 'string', 'max:120'],
                    "{$path}.*.metric" => ['required', 'in:'.implode(',', array_keys(Schema::metrics()))],
                    "{$path}.*.threshold" => ['required', 'numeric'],
                    "{$path}.*.comparison" => ['required', 'in:above,below'],
                    "{$path}.*.window" => ['required', 'string', function ($attribute, $value, $fail) {
                        try {
                            if (CarbonInterval::fromString((string) $value)->totalSeconds <= 0) {
                                $fail("“{$value}” is not a length of time.");
                            }
                        } catch (Throwable) {
                            $fail("“{$value}” is not a length of time, like 5 minutes.");
                        }
                    }],
                    "{$path}.*.options" => ['nullable', 'string', 'max:500'],
                    "{$path}.*.description" => ['nullable', 'string', 'max:255'],
                ],
            };
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    protected function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), (array) $value), fn ($item) => $item !== '')));
    }

    /**
     * @param  array<string, mixed>  $thresholds
     * @return list<array{type: string, pattern: string, ms: string}>
     */
    protected function thresholdRows(array $thresholds): array
    {
        $rows = [];

        foreach (Schema::thresholdTypes() as $type => $label) {
            foreach ((array) ($thresholds[$type] ?? []) as $pattern => $ms) {
                $rows[] = ['type' => $type, 'pattern' => (string) $pattern, 'ms' => (string) $ms];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, array<string, int>>
     */
    protected function thresholdConfig(array $rows): array
    {
        $config = array_map(fn () => [], Schema::thresholdTypes());

        foreach ($rows as $row) {
            if (is_array($row) && isset($config[$row['type'] ?? null]) && trim((string) ($row['pattern'] ?? '')) !== '') {
                $config[$row['type']][trim((string) $row['pattern'])] = (int) $row['ms'];
            }
        }

        return $config;
    }

    /**
     * "type=request, queue=default" as an array.
     *
     * @return array<string, string|int|float|bool>
     */
    protected function options(string $text): array
    {
        $options = [];

        foreach (preg_split('/\s*,\s*/', trim($text)) ?: [] as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $pair, 2));

            if ($key !== '') {
                $options[$key] = match (true) {
                    $value === 'true' => true,
                    $value === 'false' => false,
                    is_numeric($value) => $value + 0,
                    default => $value,
                };
            }
        }

        return $options;
    }

    /**
     * A secret, as much of it as is safe to show.
     *
     * @param  Field  $field
     * @return list<string>
     */
    protected function masked(array $field, mixed $value): array
    {
        if ($field['type'] === 'secrets') {
            return array_map(fn (string $url) => Webhooks::mask($url), $this->list($value));
        }

        $value = (string) ($value ?? '');

        return $value === '' ? [] : [str_repeat('•', 8).substr($value, -4)];
    }

    protected function me(): ?string
    {
        $id = app(Pulse::class)->resolveAuthenticatedUserId();

        return $id === null ? null : (string) $id;
    }
}
