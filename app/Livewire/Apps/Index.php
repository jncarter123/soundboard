<?php

namespace App\Livewire\Apps;

use App\Models\ReverbApp;
use App\Models\Team;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $creating = false;

    public ?int $editingAppId = null;

    public string $name = '';

    public string $appId = '';

    /** The team the app belongs to; null for none. */
    public ?int $teamId = null;

    public string $allowedOrigins = '';

    public int $pingInterval = 60;

    public int $activityTimeout = 30;

    public int $maxMessageSize = 10000;

    public ?int $maxConnections = null;

    public ?int $maxMessagesPerDay = null;

    public string $acceptClientEventsFrom = 'members';

    public bool $rateLimitEnabled = false;

    public int $rateLimitMaxAttempts = 60;

    public int $rateLimitDecaySeconds = 60;

    public bool $rateLimitTerminate = false;

    /**
     * The app whose credentials are shown. Only the id lives in component
     * state; the key and secret are loaded in render() so they never end up
     * in the serialized Livewire snapshot.
     */
    #[Locked]
    public ?int $revealAppId = null;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $teams = $this->creatableTeams();

        if (! auth()->user()->can('apps.create') && $teams->isEmpty()) {
            throw new AuthorizationException;
        }

        $this->resetForm();
        // Without the global permission the app must go in one of their teams.
        $this->teamId = auth()->user()->can('apps.create') ? null : $teams->first()->id;
        $this->creating = true;
    }

    public function editApp(int $id): void
    {
        $app = $this->findApp($id);
        $this->authorize('update', $app);
        $this->editingAppId = $id;
        $this->creating = false;
        $this->name = $app->name;
        $this->appId = $app->app_id;
        $this->teamId = $app->team_id;
        $this->allowedOrigins = implode(', ', $app->allowed_origins);
        $this->pingInterval = $app->ping_interval;
        $this->activityTimeout = $app->activity_timeout;
        $this->maxMessageSize = $app->max_message_size;
        $this->maxConnections = $app->max_connections;
        $this->maxMessagesPerDay = $app->max_messages_per_day;
        $this->acceptClientEventsFrom = $app->accept_client_events_from;
        $this->rateLimitEnabled = $app->rate_limit_enabled;
        $this->rateLimitMaxAttempts = $app->rate_limit_max_attempts;
        $this->rateLimitDecaySeconds = $app->rate_limit_decay_seconds;
        $this->rateLimitTerminate = $app->rate_limit_terminate;
    }

    public function saveCreate(): void
    {
        $this->validate($this->createRules());
        $this->authorize('create', [ReverbApp::class, Team::find($this->teamId)]);

        $app = ReverbApp::create([
            'app_id' => $this->appId,
            'team_id' => $this->teamId,
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            ...$this->settingsAttributes(),
        ]);

        $this->creating = false;
        $this->revealAppId = $app->id;
        $this->resetForm();
    }

    public function saveEdit(): void
    {
        $app = $this->findApp($this->editingAppId);
        $this->authorize('update', $app);
        $this->validate($this->editRules());

        $attributes = $this->settingsAttributes();

        if ($this->teamId !== $app->team_id) {
            $this->authorize('changeTeam', $app);
            $attributes['team_id'] = $this->teamId;
        }

        // app_id is immutable: clients connect with it and Pulse metrics are
        // keyed by it, so changing it would break both.
        $app->update($attributes);

        $this->closeModal();
    }

    public function regenerateCredentials(int $id): void
    {
        $app = $this->findApp($id);
        $this->authorize('update', $app);

        $app->update([
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);
        Audit::log('credentials.regenerated', 'Regenerated app credentials', $app);

        $this->revealAppId = $app->id;
    }

    public function revealCredentials(int $id): void
    {
        $app = $this->findApp($id);
        $this->authorize('update', $app);
        Audit::log('credentials.viewed', 'Viewed app credentials', $app);
        $this->revealAppId = $app->id;
    }

    public function closeReveal(): void
    {
        $this->revealAppId = null;
    }

    public function deleteApp(int $id): void
    {
        $app = $this->findApp($id);
        $this->authorize('delete', $app);
        $app->delete();
    }

    public function closeModal(): void
    {
        $this->creating = false;
        $this->editingAppId = null;
        $this->resetForm();
        $this->resetValidation();
    }

    public function render()
    {
        $user = auth()->user();

        $apps = ReverbApp::visibleTo($user)
            ->with('team')
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('app_id', 'like', "%{$this->search}%");
            }))
            ->orderBy('name')
            ->paginate(15);

        $revealed = $this->revealAppId !== null ? ReverbApp::visibleTo($user)->find($this->revealAppId) : null;
        $credentials = $revealed && $user->can('update', $revealed) ? $revealed->only(['key', 'secret']) : null;

        $creatableTeams = $this->creatableTeams();

        return view('livewire.apps.index', [
            'apps' => $apps,
            'credentials' => $credentials,
            'canCreate' => $user->can('apps.create') || $creatableTeams->isNotEmpty(),
            // The team choices in the form: where new apps can go, or when
            // editing, every team for those who may move apps.
            'teamOptions' => $this->editingAppId ? Team::orderBy('name')->get() : $creatableTeams,
            'canChooseNoTeam' => $this->editingAppId ? $user->can('apps.update') : $user->can('apps.create'),
            'canChangeTeam' => $this->editingAppId === null || $user->can('apps.update'),
        ])->layout('components.layouts.app');
    }

    /**
     * An app the current user can see; others are not found.
     */
    private function findApp(?int $id): ReverbApp
    {
        return ReverbApp::visibleTo(auth()->user())->findOrFail($id);
    }

    /**
     * Teams the current user may create apps in: all of them with the global
     * permission, otherwise those where their role allows it.
     *
     * @return Collection<int, Team>
     */
    private function creatableTeams(): Collection
    {
        $user = auth()->user();

        if ($user->can('apps.create')) {
            return Team::orderBy('name')->get();
        }

        return $user->teams
            ->filter(fn (Team $team) => $user->teamRole($team->id)?->grants('apps.create'))
            ->sortBy('name')
            ->values();
    }

    private function resetForm(): void
    {
        $this->reset([
            'name', 'appId', 'teamId', 'allowedOrigins', 'pingInterval', 'activityTimeout', 'maxMessageSize', 'maxConnections', 'maxMessagesPerDay',
            'acceptClientEventsFrom', 'rateLimitEnabled', 'rateLimitMaxAttempts', 'rateLimitDecaySeconds', 'rateLimitTerminate',
        ]);
    }

    /**
     * The form's settings as model attributes, shared by create and edit.
     */
    private function settingsAttributes(): array
    {
        return [
            'name' => $this->name,
            'allowed_origins' => $this->parseOrigins(),
            'ping_interval' => $this->pingInterval,
            'activity_timeout' => $this->activityTimeout,
            'max_message_size' => $this->maxMessageSize,
            'max_connections' => $this->maxConnections,
            'max_messages_per_day' => $this->maxMessagesPerDay,
            'accept_client_events_from' => $this->acceptClientEventsFrom,
            'rate_limit_enabled' => $this->rateLimitEnabled,
            'rate_limit_max_attempts' => $this->rateLimitMaxAttempts,
            'rate_limit_decay_seconds' => $this->rateLimitDecaySeconds,
            'rate_limit_terminate' => $this->rateLimitTerminate,
        ];
    }

    private function settingsRules(): array
    {
        return [
            'maxMessagesPerDay' => ['nullable', 'integer', 'min:1'],
            'acceptClientEventsFrom' => ['required', Rule::in(ReverbApp::CLIENT_EVENTS_FROM)],
            'rateLimitEnabled' => ['boolean'],
            'rateLimitMaxAttempts' => ['required', 'integer', 'min:1'],
            'rateLimitDecaySeconds' => ['required', 'integer', 'min:1'],
            'rateLimitTerminate' => ['boolean'],
        ];
    }

    private function parseOrigins(): array
    {
        return ReverbApp::normalizeOrigins(preg_split('/[\s,]+/', $this->allowedOrigins));
    }

    /**
     * Reverb rejects every connection when an app has no allowed origins, so
     * require at least one. The raw field is checked after normalization.
     */
    private function originsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($this->parseOrigins() === []) {
                $fail('Add at least one origin. Use * to allow any origin.');
            }
        };
    }

    private function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'teamId' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'appId' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:reverb_apps,app_id'],
            'allowedOrigins' => ['required', 'string', $this->originsRule()],
            'pingInterval' => ['required', 'integer', 'min:1'],
            'activityTimeout' => ['required', 'integer', 'min:1'],
            'maxMessageSize' => ['required', 'integer', 'min:1'],
            'maxConnections' => ['nullable', 'integer', 'min:1'],
            ...$this->settingsRules(),
        ];
    }

    private function editRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'teamId' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'allowedOrigins' => ['required', 'string', $this->originsRule()],
            'pingInterval' => ['required', 'integer', 'min:1'],
            'activityTimeout' => ['required', 'integer', 'min:1'],
            'maxMessageSize' => ['required', 'integer', 'min:1'],
            'maxConnections' => ['nullable', 'integer', 'min:1'],
            ...$this->settingsRules(),
        ];
    }
}
