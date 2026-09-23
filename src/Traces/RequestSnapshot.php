<?php

namespace Elazaroo\PulseBoosted\Traces;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * What a failed request carried, with the secrets taken out.
 *
 * A 500 is often only reproducible with the input that caused it. Headers are
 * kept for every server error, with credentials replaced; the body is kept
 * only when the application opts in, since it is where personal data lives.
 *
 * @internal
 */
class RequestSnapshot
{
    /**
     * The most the snapshot may take up, serialised.
     */
    public const MAX_BYTES = 16_384;

    public function __construct(protected Repository $config)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function capture(Request $request): array
    {
        $snapshot = [
            'headers' => $this->headers($request),
        ];

        if (($query = $request->query->all()) !== []) {
            $snapshot['query'] = $this->redact($query);
        }

        if ($this->config->get('pulse-boosted.traces.request.capture_payload', false)) {
            $payload = $this->redact($request->request->all());

            if ($files = $this->files($request->allFiles())) {
                $payload['_files'] = $files;
            }

            if ($payload !== []) {
                $snapshot['payload'] = $payload;
            }
        }

        // A request body can be anything; the snapshot must stay a size that
        // fits in a row.
        if (strlen((string) json_encode($snapshot)) > self::MAX_BYTES) {
            unset($snapshot['payload']);
            $snapshot['truncated'] = true;
        }

        return $snapshot;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(Request $request): array
    {
        $redact = array_map('strtolower', (array) $this->config->get('pulse-boosted.traces.request.redact_headers', []));

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);

            // Basic auth arrives in PHP_AUTH_* as well as the header.
            if (str_starts_with($name, 'php-auth-')) {
                continue;
            }

            $headers[$name] = in_array($name, $redact, true)
                ? '[redacted]'
                : Str::limit(implode(', ', array_map('strval', (array) $values)), 500);
        }

        ksort($headers);

        return $headers;
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    protected function redact(array $input): array
    {
        $fields = array_map('strtolower', (array) $this->config->get('pulse-boosted.traces.request.redact_fields', []));

        $walk = function (array $values) use (&$walk, $fields): array {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $values[$key] = $walk($value);
                } elseif (in_array(strtolower((string) $key), $fields, true)) {
                    $values[$key] = '['.strlen(is_scalar($value) ? (string) $value : '').' bytes redacted]';
                } elseif (is_string($value)) {
                    $values[$key] = Str::limit($value, 1000);
                }
            }

            return $values;
        };

        return $walk($input);
    }

    /**
     * @param  array<mixed>  $files
     * @return array<mixed>
     */
    protected function files(array $files): array
    {
        return array_map(fn ($file) => match (true) {
            is_array($file) => $this->files($file),
            $file instanceof UploadedFile => ['name' => $file->getClientOriginalName(), 'size' => $file->getSize()],
            default => null,
        }, $files);
    }
}
