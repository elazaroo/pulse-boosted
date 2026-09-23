<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Carbon\CarbonImmutable;
use Elazaroo\PulseBoosted\Deployments\Deployments;
use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Logging\LogStream;
use Elazaroo\PulseBoosted\Pulse;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Elazaroo\PulseBoosted\Support\Location;
use Elazaroo\PulseBoosted\Support\People;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Everything that went wrong, grouped into the problems behind it — or
 * listed one entry at a time.
 *
 * Grouped, it asks which problems exist — exceptions, errors and warnings the
 * application logged, executions over their threshold — whether they are
 * still happening, how many people they reached, and whether anybody has
 * dealt with them. Every entry lists each log line and exception as it
 * happened, the way a log file would.
 *
 * @phpstan-import-type IssueRow from IssueRepository
 *
 * @internal
 */
#[Lazy(isolate: false)]
class Issues extends Card
{
    use Concerns\FiltersByUser;

    public const PER_PAGE = 15;

    public const STREAM_PER_PAGE = 20;

    /**
     * Grouped into issues, or every entry as it happened.
     *
     * @var 'grouped'|'stream'
     */
    #[Url(as: 'issue_view')]
    public string $view = 'grouped';

    /**
     * In the stream: a log level, 'exception', or everything.
     */
    #[Url(as: 'log_level')]
    public string $logLevel = '';

    #[Url(as: 'issue_status')]
    public string $status = 'open';

    #[Url(as: 'issue_q')]
    public string $search = '';

    /**
     * Exceptions, PHP errors, or both.
     *
     * An Exception is usually something the application anticipated; an Error
     * is usually a bug in the code. They want looking at differently.
     *
     * @var ''|'exception'|'error'
     */
    #[Url(as: 'issue_kind')]
    public string $kind = '';

    /**
     * Caught and reported by the application, escaped to the handler, or both.
     *
     * @var ''|'handled'|'unhandled'
     */
    #[Url(as: 'issue_handled')]
    public string $handled = '';

    /**
     * Most recently seen, or most frequent.
     *
     * @var 'latest'|'count'
     */
    #[Url(as: 'issue_sort')]
    public string $orderBy = 'latest';

    /**
     * Anyone, the person looking, or nobody.
     *
     * @var ''|'me'|'none'
     */
    #[Url(as: 'issue_assignee')]
    public string $assignee = '';

    #[Url(as: 'issue')]
    public ?string $selected = null;

    /**
     * What is being written in the open issue's comment box.
     */
    public string $comment = '';

    public int $page = 1;

    /**
     * Reset paging when the view changes underneath it.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /**
     * Show the trace an entry came from.
     */
    public function showTrace(string $traceId): void
    {
        $this->dispatch('open-trace', traceId: $traceId);
    }

    /**
     * Open an issue.
     */
    public function select(string $fingerprint): void
    {
        $this->selected = $fingerprint;
        $this->comment = '';
    }

    /**
     * Close it.
     */
    public function deselect(): void
    {
        $this->selected = null;
    }

    /**
     * Mark an issue dealt with. If it happens again it reopens itself, which
     * is how a regression announces itself.
     */
    public function resolve(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->setStatus($fingerprint, 'resolved', $this->me());
    }

    /**
     * Stop hearing about an issue, even if it keeps happening.
     */
    public function ignore(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->setStatus($fingerprint, 'ignored', $this->me());
    }

    /**
     * Put it back in the open list.
     */
    public function reopen(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->setStatus($fingerprint, 'open', $this->me());
    }

    /**
     * Make somebody responsible for an issue, or nobody.
     */
    public function assign(string $fingerprint, ?string $assignee, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->assign($fingerprint, $assignee, $this->me());
    }

    /**
     * Take an issue on.
     */
    public function assignToMe(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        if (($me = $this->me()) !== null) {
            $issues->assign($fingerprint, $me, $me);
        }
    }

    /**
     * Write something about the open issue. Anyone who can see the dashboard
     * can: saying what you found changes nothing.
     */
    public function addComment(string $fingerprint, IssueRepository $issues): void
    {
        $this->validate(['comment' => ['required', 'string', 'max:5000']]);

        $issues->comment($fingerprint, $this->comment, $this->me());

        $this->comment = '';
    }

    /**
     * The person looking at the dashboard, as the application knows them.
     */
    protected function me(): ?string
    {
        $id = app(Pulse::class)->resolveAuthenticatedUserId();

        return $id === null ? null : (string) $id;
    }

    /**
     * Render the component.
     */
    public function render(IssueRepository $issues, QueueActions $actions, Deployments $deployments, LogStream $stream, People $people): Renderable
    {
        $filters = array_filter([
            'status' => $this->status ?: null,
            'kind' => $this->kind ?: null,
            'handled' => $this->handled ?: null,
            'assignee' => match ($this->assignee) {
                'me' => $this->me() ?? 'none',
                'none' => 'none',
                default => null,
            },
            'user' => $this->user ?: null,
            'search' => $this->search ?: null,
        ], fn ($value) => $value !== null);

        $list = $issues->issues($filters, self::PER_PAGE, ($this->page - 1) * self::PER_PAGE, $this->orderBy);

        return View::make('pulse-boosted::livewire.issues', [
            'enabled' => $issues->enabled(),
            'issues' => $list,
            'assignees' => $people->resolve($list->pluck('assignee')),
            'me' => $this->me(),
            'total' => $issues->count($filters),
            'statusCounts' => $issues->countsByStatus(),
            'kindCounts' => $issues->countsByKind($filters),
            'detail' => $this->detail($issues, $people),
            'canManage' => $actions->allowed(),
            // "New" only means something once there is an earlier deploy to
            // be new since.
            'latestDeploy' => ($recent = $deployments->recent(2))->count() > 1 ? $recent->first()?->version : null,
            ...($this->view === 'stream' ? [
                'entries' => $stream->entries($this->logLevel, trim($this->search), self::STREAM_PER_PAGE, ($this->page - 1) * self::STREAM_PER_PAGE, $this->user),
                'counts' => $stream->counts(),
            ] : []),
        ]);
    }

    /**
     * The issue whose panel is open.
     *
     * @return array<string, mixed>|null
     */
    protected function detail(IssueRepository $issues, People $people): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        $issue = $issues->find($this->selected);

        if ($issue === null) {
            return ['missing' => true];
        }

        $frames = json_decode((string) ($issue->trace ?? ''), true);
        $frames = is_array($frames) ? $frames : [];

        return [
            'groups' => $this->groupFrames($frames),
            'markdown' => $this->markdown($issue, $frames),
            'missing' => false,
            'issue' => $issue,
            'users' => $issues->affectedUsers($this->selected),
            'occurrences' => $occurrences = $issues->recentOccurrences($this->selected),
            'activity' => $activity = $issues->activity($this->selected),
            // Everyone worth offering as an assignee, and everyone named in
            // the history, resolved in one go.
            'people' => $people->resolve([
                ...$issues->people(),
                ...$this->configuredAssignees(),
                $this->me(),
                $issue->assignee,
                ...$activity->pluck('user_id'),
                ...$activity->where('type', 'assigned')->pluck('body'),
                ...$occurrences->pluck('user_id'),
            ]),
            'candidates' => array_values(array_unique(array_filter([
                ...$issues->people(),
                ...$this->configuredAssignees(),
                $this->me(),
            ]))),
        ];
    }

    /**
     * The users the application listed as people issues can be given to.
     *
     * @return list<string>
     */
    protected function configuredAssignees(): array
    {
        $ids = config('pulse-boosted.issues.assignees', []);

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return array_values(array_filter(array_map(fn ($id) => trim((string) $id), (array) $ids)));
    }

    /**
     * Runs of framework frames folded together, so the application's own
     * frames — the ones worth reading — are not lost among forty of Laravel's.
     *
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array<string, mixed>>
     */
    protected function groupFrames(array $frames): array
    {
        $groups = [];
        $vendor = [];

        foreach ($frames as $frame) {
            if ($frame['app'] ?? false) {
                if ($vendor !== []) {
                    $groups[] = ['app' => false, 'frames' => $vendor];
                    $vendor = [];
                }

                $groups[] = ['app' => true, 'frames' => [$frame]];
            } else {
                $vendor[] = $frame;
            }
        }

        if ($vendor !== []) {
            $groups[] = ['app' => false, 'frames' => $vendor];
        }

        return $groups;
    }

    /**
     * The issue written out for pasting into a ticket or a chat.
     *
     * @param  IssueRow  $issue
     * @param  array<int, array<string, mixed>>  $frames
     */
    protected function markdown(object $issue, array $frames): string
    {
        $lines = ["## {$issue->class}", ''];

        if ($issue->message) {
            $lines[] = $issue->message;
            $lines[] = '';
        }

        if (($location = Location::relative($issue->file, $issue->line)) !== null) {
            $lines[] = '- Location: `'.$location.'`';
        }

        $lines[] = '- '.match (true) {
            $issue->kind === 'performance' => 'Slow',
            $issue->kind === 'log' => 'Logged '.$issue->level,
            (bool) $issue->handled => 'Handled',
            default => 'Unhandled',
        }.', '.number_format((int) $issue->occurrences).' occurrences';
        $lines[] = '- First seen '.CarbonImmutable::createFromTimestamp($issue->first_seen_at)->toDateTimeString()
            .', last seen '.CarbonImmutable::createFromTimestamp($issue->last_seen_at)->toDateTimeString();

        if ($issue->laravel_version) {
            $lines[] = "- Laravel {$issue->laravel_version}, PHP {$issue->php_version}";
        }

        $lines[] = '';
        $lines[] = '```';

        foreach (array_slice($frames, 0, 20) as $frame) {
            $lines[] = trim(($frame['call'] ?? '').' '.($frame['file'] ?? '[internal]').(isset($frame['line']) ? ':'.$frame['line'] : ''));
        }

        $lines[] = '```';

        return implode("\n", $lines)."\n";
    }

    /**
     * Changing an issue's state is a change, so it needs the same gate as the
     * rest of them.
     */
    protected function ensureAllowed(QueueActions $actions): void
    {
        abort_unless($actions->allowed(), 403);
    }
}
