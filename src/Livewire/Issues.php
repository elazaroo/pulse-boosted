<?php

namespace Elazaroo\PulseBoosted\Livewire;

use Elazaroo\PulseBoosted\Issues\IssueRepository;
use Elazaroo\PulseBoosted\Queues\QueueActions;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Exceptions grouped into the problems behind them.
 *
 * The Exceptions card counts throwables over a period; this one asks which
 * bugs exist, whether they are still happening, how many people they reached,
 * and whether anybody has dealt with them.
 *
 * @internal
 */
#[Lazy]
class Issues extends Card
{
    public const PER_PAGE = 15;

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
     * Most recently seen, or most frequent.
     *
     * @var 'latest'|'count'
     */
    #[Url(as: 'issue_sort')]
    public string $orderBy = 'latest';

    #[Url(as: 'issue')]
    public ?string $selected = null;

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
     * Open an issue.
     */
    public function select(string $fingerprint): void
    {
        $this->selected = $fingerprint;
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

        $issues->setStatus($fingerprint, 'resolved');
    }

    /**
     * Stop hearing about an issue, even if it keeps happening.
     */
    public function ignore(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->setStatus($fingerprint, 'ignored');
    }

    /**
     * Put it back in the open list.
     */
    public function reopen(string $fingerprint, IssueRepository $issues, QueueActions $actions): void
    {
        $this->ensureAllowed($actions);

        $issues->setStatus($fingerprint, 'open');
    }

    /**
     * Render the component.
     */
    public function render(IssueRepository $issues, QueueActions $actions): Renderable
    {
        $filters = array_filter([
            'status' => $this->status ?: null,
            'kind' => $this->kind ?: null,
            'search' => $this->search ?: null,
        ], fn ($value) => $value !== null);

        return View::make('pulse-boosted::livewire.issues', [
            'enabled' => $issues->enabled(),
            'issues' => $issues->issues($filters, self::PER_PAGE, ($this->page - 1) * self::PER_PAGE, $this->orderBy),
            'total' => $issues->count($filters),
            'statusCounts' => $issues->countsByStatus(),
            'kindCounts' => $issues->countsByKind($filters),
            'detail' => $this->detail($issues),
            'canManage' => $actions->allowed(),
        ]);
    }

    /**
     * The issue whose panel is open.
     *
     * @return array<string, mixed>|null
     */
    protected function detail(IssueRepository $issues): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        $issue = $issues->find($this->selected);

        if ($issue === null) {
            return ['missing' => true];
        }

        return [
            'missing' => false,
            'issue' => $issue,
            'users' => $issues->affectedUsers($this->selected),
            'occurrences' => $issues->recentOccurrences($this->selected),
        ];
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
