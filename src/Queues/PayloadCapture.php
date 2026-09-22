<?php

namespace Elazaroo\PulseBoosted\Queues;

use Closure;
use Elazaroo\PulseBoosted\Recorders\Jobs;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Reads a job's constructor arguments off the live object as it is queued.
 *
 * This deliberately never touches the serialized `data.command` string in a
 * stored payload. Unserializing that would mean instantiating arbitrary classes
 * from database contents, which is a remote code execution primitive; and when
 * the job is encrypted it would not work anyway. Reading the live object costs
 * nothing extra and produces JSON we can render safely later.
 *
 * @internal
 */
class PayloadCapture
{
    /**
     * Properties every queueable job carries that describe the queue rather
     * than the work, and which are already stored in their own columns.
     *
     * @var list<string>
     */
    protected array $infrastructureProperties = [
        'afterCommit',
        'batchId',
        'chainCatchCallbacks',
        'chainConnection',
        'chained',
        'chainQueue',
        'connection',
        'delay',
        'deleteWhenMissingModels',
        'fakeBatch',
        'fakeBatchCallbacks',
        'failOnTimeout',
        'job',
        'maxExceptions',
        'middleware',
        'queue',
        'shouldBeEncrypted',
        'tries',
    ];

    /**
     * Create a new payload capture instance.
     */
    public function __construct(
        protected Repository $config,
    ) {
        //
    }

    /**
     * Whether argument capture is switched on.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get(
            'pulse-boosted.recorders.'.Jobs::class.'.capture_payload', false
        );
    }

    /**
     * Capture a job's arguments as a JSON string, or null when disabled or
     * when there is nothing worth storing.
     */
    public function capture(mixed $job): ?string
    {
        if (! $this->enabled() || ! is_object($job)) {
            return null;
        }

        $arguments = $this->properties($job);

        if ($arguments === []) {
            return null;
        }

        return json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
    }

    /**
     * Read the job's own properties, skipping the queueing machinery.
     *
     * @return array<string, mixed>
     */
    protected function properties(object $job): array
    {
        $captured = [];

        foreach ((new ReflectionObject($job))->getProperties() as $property) {
            if ($property->isStatic() || $this->isInfrastructure($property)) {
                continue;
            }

            $name = $property->getName();

            if (! $property->isInitialized($job)) {
                continue;
            }

            $captured[$name] = $this->shouldRedact($name)
                ? '[redacted]'
                : $this->value($property->getValue($job), 1);
        }

        return $captured;
    }

    /**
     * Whether the property belongs to Laravel's queueing traits rather than
     * to the job itself.
     */
    protected function isInfrastructure(ReflectionProperty $property): bool
    {
        return in_array($property->getName(), $this->infrastructureProperties, true);
    }

    /**
     * Whether a key looks like it holds a secret.
     */
    protected function shouldRedact(string $key): bool
    {
        $key = Str::lower($key);

        foreach ($this->redactionPatterns() as $pattern) {
            if (str_contains($key, Str::lower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured list of key fragments that mark a value as secret.
     *
     * @return list<string>
     */
    protected function redactionPatterns(): array
    {
        return $this->config->get('pulse-boosted.recorders.'.Jobs::class.'.redact', []);
    }

    /**
     * Reduce a value to something JSON encodable, without ever calling a
     * method on it that might have side effects.
     */
    protected function value(mixed $value, int $depth): mixed
    {
        if ($depth > $this->maxDepth()) {
            return '[max depth]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return Str::limit($value, $this->maxLength());
        }

        if (is_array($value)) {
            return $this->arrayValue($value, $depth);
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum
                ? $value->value
                : $value->name;
        }

        if ($value instanceof Model) {
            return $this->modelValue($value);
        }

        if (is_object($value)) {
            return $this->objectValue($value, $depth);
        }

        return '['.gettype($value).']';
    }

    /**
     * Walk an array, redacting any key that looks like a secret.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected function arrayValue(array $value, int $depth): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $result[$key] = is_string($key) && $this->shouldRedact($key)
                ? '[redacted]'
                : $this->value($item, $depth + 1);
        }

        return $result;
    }

    /**
     * Describe an Eloquent model by its identity rather than its attributes,
     * which is both smaller and far less likely to leak anything.
     *
     * @return array<string, mixed>
     */
    protected function modelValue(Model $value): array
    {
        return [
            '__model' => $value::class,
            'key' => $value->getKey(),
        ];
    }

    /**
     * Describe a plain object by its public state.
     *
     * @return array<string, mixed>
     */
    protected function objectValue(object $value, int $depth): array
    {
        $described = ['__class' => $value::class];

        try {
            foreach (get_object_vars($value) as $key => $item) {
                $described[$key] = $this->shouldRedact($key)
                    ? '[redacted]'
                    : $this->value($item, $depth + 1);
            }
        } catch (Throwable) {
            // Some objects throw from property access; the class name alone
            // is still useful, so keep what we have.
        }

        return $described;
    }

    /**
     * How deep to walk nested values.
     */
    protected function maxDepth(): int
    {
        return (int) $this->config->get('pulse-boosted.recorders.'.Jobs::class.'.max_depth', 4);
    }

    /**
     * The longest a single captured string may be.
     */
    protected function maxLength(): int
    {
        return (int) $this->config->get('pulse-boosted.recorders.'.Jobs::class.'.max_length', 2_000);
    }
}
