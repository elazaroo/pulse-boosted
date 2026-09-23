<?php

use Elazaroo\PulseBoosted\Http\Middleware\Authorize;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Recorders;

return [

    /*
    |--------------------------------------------------------------------------
    | Pulse Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain which the Pulse dashboard will be accessible from.
    | When set to null, the dashboard will reside under the same domain as
    | the application. Remember to configure your DNS entries correctly.
    |
    */

    'domain' => env('PULSE_BOOSTED_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Pulse Path
    |--------------------------------------------------------------------------
    |
    | This is the path which the Pulse dashboard will be accessible from. Feel
    | free to change this path to anything you'd like. Note that this won't
    | affect the path of the internal API that is never exposed to users.
    |
    */

    'path' => env('PULSE_BOOSTED_PATH', 'pulse-boosted'),

    /*
    |--------------------------------------------------------------------------
    | Pulse Master Switch
    |--------------------------------------------------------------------------
    |
    | This configuration option may be used to completely disable all Pulse
    | data recorders regardless of their individual configurations. This
    | provides a single option to quickly disable all Pulse recording.
    |
    */

    'enabled' => env('PULSE_BOOSTED_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Pulse Storage Driver
    |--------------------------------------------------------------------------
    |
    | This configuration option determines which storage driver will be used
    | while storing entries from Pulse's recorders. In addition, you also
    | may provide any options to configure the selected storage driver.
    |
    */

    'storage' => [
        'driver' => env('PULSE_BOOSTED_STORAGE_DRIVER', 'database'),

        'trim' => [
            'keep' => env('PULSE_BOOSTED_STORAGE_KEEP', '7 days'),
        ],

        'database' => [
            'connection' => env('PULSE_BOOSTED_DB_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Ingest Driver
    |--------------------------------------------------------------------------
    |
    | This configuration options determines the ingest driver that will be used
    | to capture entries from Pulse's recorders. Ingest drivers are great to
    | free up your request workers quickly by offloading the data storage.
    |
    */

    'ingest' => [
        'driver' => env('PULSE_BOOSTED_INGEST_DRIVER', 'storage'),

        'buffer' => env('PULSE_BOOSTED_INGEST_BUFFER', 5_000),

        'trim' => [
            'lottery' => [1, 1_000],
            'keep' => env('PULSE_BOOSTED_INGEST_KEEP', '7 days'),
        ],

        'redis' => [
            'connection' => env('PULSE_BOOSTED_REDIS_CONNECTION'),
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Cache Driver
    |--------------------------------------------------------------------------
    |
    | This configuration option determines the cache driver that will be used
    | for various tasks, including caching dashboard results, establishing
    | locks for events that should only occur on one server and signals.
    |
    */

    'cache' => env('PULSE_BOOSTED_CACHE_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Pulse Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Pulse route, giving you the
    | chance to add your own middleware to this list or change any of the
    | existing middleware. Of course, reasonable defaults are provided.
    |
    */

    'middleware' => [
        'web',
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Traces
    |--------------------------------------------------------------------------
    |
    | A trace ties everything one execution did — its queries, cache reads,
    | dispatched jobs, outgoing calls, exceptions and log lines — to the
    | request, command, scheduled task or job that caused them. Counters tell
    | you something is wrong; a trace tells you what led to it.
    |
    | The cost is a row per event, so sampling happens at the entry point: an
    | execution is recorded whole or not at all. Half a trace would be worse
    | than none, because the missing parts would read as idle time.
    |
    */

    'traces' => [
        'enabled' => env('PULSE_BOOSTED_TRACES_ENABLED', true),

        /*
         * The share of executions to record. Start low and raise it once you
         * know what your traffic costs; 1.0 records everything.
         */
        'sample_rate' => env('PULSE_BOOSTED_TRACES_SAMPLE_RATE', 0.1),

        /*
         * Per-context overrides. Commands and scheduled tasks are rare enough
         * to record in full, and a failed job is usually the thing you came
         * to look at, so jobs are sampled higher than requests.
         */
        'sample_rates' => [
            'request' => env('PULSE_BOOSTED_TRACES_REQUEST_SAMPLE_RATE', 0.1),
            'job' => env('PULSE_BOOSTED_TRACES_JOB_SAMPLE_RATE', 0.5),
            'command' => env('PULSE_BOOSTED_TRACES_COMMAND_SAMPLE_RATE', 1.0),
            'schedule' => env('PULSE_BOOSTED_TRACES_SCHEDULE_SAMPLE_RATE', 1.0),
        ],

        /*
         * The most events one trace may hold. A loop that queries in a
         * thousand iterations should not write a thousand rows; the timeline
         * says how many were dropped.
         */
        'max_events' => 500,

        /*
         * The lowest log level that makes it into a trace.
         */
        'log_level' => env('PULSE_BOOSTED_TRACES_LOG_LEVEL', 'debug'),

        /*
         * Traces are far heavier than aggregates, so they are kept for a day
         * rather than a week.
         */
        'trim' => [
            'keep' => env('PULSE_BOOSTED_TRACES_KEEP', '24 hours'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Inspection
    |--------------------------------------------------------------------------
    |
    | The queue explorer reads the queue backend directly to show what is
    | waiting right now. This is separate from the recorded job history: it
    | costs a query against your queue on every refresh, and how much it can
    | show depends on the driver. Listing can be disabled independently of
    | counting, since listing is the more expensive of the two.
    |
    */

    'queues' => [
        'enabled' => env('PULSE_BOOSTED_QUEUE_INSPECTION_ENABLED', true),

        /*
         * Which queue connections to inspect. When null, every connection
         * configured in config/queue.php is offered.
         */
        'connections' => null,

        /*
         * Reading the contents of a queue, rather than just its size. Turn
         * this off if your queues are large enough that scanning them is a
         * problem.
         */
        'listing' => env('PULSE_BOOSTED_QUEUE_LISTING_ENABLED', true),

        /*
         * The most rows a single listing query may return.
         */
        'max_results' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse Recorders
    |--------------------------------------------------------------------------
    |
    | The following array lists the "recorders" that will be registered with
    | Pulse, along with their configuration. Recorders gather application
    | event data from requests and tasks to pass to your ingest driver.
    |
    */

    'recorders' => [
        Recorders\CacheInteractions::class => [
            'enabled' => env('PULSE_BOOSTED_CACHE_INTERACTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_CACHE_INTERACTIONS_SAMPLE_RATE', 1),
            'ignore' => [
                ...Pulse::defaultVendorCacheKeys(),
            ],
            'groups' => [
                '/^job-exceptions:.*/' => 'job-exceptions:*',
                // '/:\d+/' => ':*',
            ],
        ],

        Recorders\Exceptions::class => [
            'enabled' => env('PULSE_BOOSTED_EXCEPTIONS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_EXCEPTIONS_SAMPLE_RATE', 1),
            'location' => env('PULSE_BOOSTED_EXCEPTIONS_LOCATION', true),
            'ignore' => [
                // '/^Package\\\\Exceptions\\\\/',
            ],
        ],

        Recorders\Jobs::class => [
            'enabled' => env('PULSE_BOOSTED_JOBS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_JOBS_SAMPLE_RATE', 1),

            /*
             * Job payloads routinely carry personal data, API tokens and
             * credentials, so arguments are not captured unless you ask for
             * them. When enabled, they are read from the live job object as it
             * is queued, redacted, and stored as JSON.
             */
            'capture_payload' => env('PULSE_BOOSTED_JOBS_CAPTURE_PAYLOAD', false),

            /*
             * Values whose key matches any of these patterns are replaced with
             * a placeholder before anything is written. Matching is done on the
             * key, case insensitively, as a substring.
             */
            'redact' => [
                'password',
                'secret',
                'token',
                'api_key',
                'apikey',
                'authorization',
                'auth',
                'credential',
                'private_key',
                'credit_card',
                'card_number',
                'cvv',
                'ssn',
            ],

            /*
             * How deep to walk a job's properties when capturing arguments, and
             * how many characters a single captured value may contribute.
             */
            'max_depth' => 4,
            'max_length' => 2_000,

            'trim' => [
                'keep' => env('PULSE_BOOSTED_JOBS_KEEP', '7 days'),
            ],

            'ignore' => [
                // '/^Package\\Jobs\\/',
            ],
        ],

        Recorders\Queues::class => [
            'enabled' => env('PULSE_BOOSTED_QUEUES_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_QUEUES_SAMPLE_RATE', 1),
            'ignore' => [
                // '/^Package\\\\Jobs\\\\/',
            ],
        ],

        Recorders\Traces::class => [
            'enabled' => env('PULSE_BOOSTED_TRACES_ENABLED', true),

            /*
             * Routes whose traces are not worth keeping. Livewire's update
             * endpoint is here because the dashboard polls it every few
             * seconds; remove it if you want traces of your own Livewire
             * components. The dashboard's own routes are always excluded.
             */
            'ignore' => [
                '#livewire[^/]*/update$#',
                // '#^health$#',
            ],
        ],

        Recorders\Workers::class => [
            'enabled' => env('PULSE_BOOSTED_WORKERS_ENABLED', true),
        ],

        Recorders\Servers::class => [
            'server_name' => env('PULSE_BOOSTED_SERVER_NAME', gethostname()),
            'directories' => explode(':', env('PULSE_BOOSTED_SERVER_DIRECTORIES', '/')),
        ],

        Recorders\SlowJobs::class => [
            'enabled' => env('PULSE_BOOSTED_SLOW_JOBS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_SLOW_JOBS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_BOOSTED_SLOW_JOBS_THRESHOLD', 1000),
            'ignore' => [
                // '/^Package\\\\Jobs\\\\/',
            ],
        ],

        Recorders\SlowOutgoingRequests::class => [
            'enabled' => env('PULSE_BOOSTED_SLOW_OUTGOING_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_SLOW_OUTGOING_REQUESTS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_BOOSTED_SLOW_OUTGOING_REQUESTS_THRESHOLD', 1000),
            'ignore' => [
                // '#^http://127\.0\.0\.1:13714#', // Inertia SSR...
            ],
            'groups' => [
                // '#^https://api\.github\.com/repos/.*$#' => 'api.github.com/repos/*',
                // '#^https?://([^/]*).*$#' => '\1',
                // '#/\d+#' => '/*',
            ],
        ],

        Recorders\SlowQueries::class => [
            'enabled' => env('PULSE_BOOSTED_SLOW_QUERIES_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_SLOW_QUERIES_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_BOOSTED_SLOW_QUERIES_THRESHOLD', 1000),
            'location' => env('PULSE_BOOSTED_SLOW_QUERIES_LOCATION', true),
            'max_query_length' => env('PULSE_BOOSTED_SLOW_QUERIES_MAX_QUERY_LENGTH'),
            'ignore' => [
                '/(["`])pulse_[\w]+?\1/', // Pulse tables...
                '/(["`])telescope_[\w]+?\1/', // Telescope tables...
            ],
        ],

        Recorders\SlowRequests::class => [
            'enabled' => env('PULSE_BOOSTED_SLOW_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_SLOW_REQUESTS_SAMPLE_RATE', 1),
            'threshold' => env('PULSE_BOOSTED_SLOW_REQUESTS_THRESHOLD', 1000),
            'ignore' => [
                '#^/'.env('PULSE_BOOSTED_PATH', 'pulse-boosted').'$#', // Pulse dashboard...
                '#^/telescope#', // Telescope dashboard...
            ],
        ],

        Recorders\UserJobs::class => [
            'enabled' => env('PULSE_BOOSTED_USER_JOBS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_USER_JOBS_SAMPLE_RATE', 1),
            'ignore' => [
                // '/^Package\\\\Jobs\\\\/',
            ],
        ],

        Recorders\UserRequests::class => [
            'enabled' => env('PULSE_BOOSTED_USER_REQUESTS_ENABLED', true),
            'sample_rate' => env('PULSE_BOOSTED_USER_REQUESTS_SAMPLE_RATE', 1),
            'ignore' => [
                '#^/'.env('PULSE_BOOSTED_PATH', 'pulse-boosted').'$#', // Pulse dashboard...
                '#^/telescope#', // Telescope dashboard...
            ],
        ],
    ],
];
