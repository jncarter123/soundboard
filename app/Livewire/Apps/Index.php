<?php

namespace App\Livewire\Apps;

use App\Models\ReverbApp;
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

    public string $allowedOrigins = '';

    public int $pingInterval = 60;

    public int $activityTimeout = 30;

    public int $maxMessageSize = 10000;

    public ?int $maxConnections = null;

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
        $this->authorize('apps.create');
        $this->resetForm();
        $this->creating = true;
    }

    public function editApp(int $id): void
    {
        $this->authorize('apps.update');
        $app = ReverbApp::findOrFail($id);
        $this->editingAppId = $id;
        $this->creating = false;
        $this->name = $app->name;
        $this->appId = $app->app_id;
        $this->allowedOrigins = implode(', ', $app->allowed_origins);
        $this->pingInterval = $app->ping_interval;
        $this->activityTimeout = $app->activity_timeout;
        $this->maxMessageSize = $app->max_message_size;
        $this->maxConnections = $app->max_connections;
    }

    public function saveCreate(): void
    {
        $this->authorize('apps.create');
        $this->validate($this->createRules());

        $app = ReverbApp::create([
            'name' => $this->name,
            'app_id' => $this->appId,
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
            'allowed_origins' => $this->parseOrigins(),
            'ping_interval' => $this->pingInterval,
            'activity_timeout' => $this->activityTimeout,
            'max_message_size' => $this->maxMessageSize,
            'max_connections' => $this->maxConnections,
        ]);

        $this->creating = false;
        $this->revealAppId = $app->id;
        $this->resetForm();
    }

    public function saveEdit(): void
    {
        $this->authorize('apps.update');
        $this->validate($this->editRules());

        // app_id is immutable: clients connect with it and Pulse metrics are
        // keyed by it, so changing it would break both.
        $app = ReverbApp::findOrFail($this->editingAppId);
        $app->update([
            'name' => $this->name,
            'allowed_origins' => $this->parseOrigins(),
            'ping_interval' => $this->pingInterval,
            'activity_timeout' => $this->activityTimeout,
            'max_message_size' => $this->maxMessageSize,
            'max_connections' => $this->maxConnections,
        ]);

        $this->closeModal();
    }

    public function regenerateCredentials(int $id): void
    {
        $this->authorize('apps.update');
        $app = ReverbApp::findOrFail($id);

        $app->update([
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);

        $this->revealAppId = $app->id;
    }

    public function revealCredentials(int $id): void
    {
        $this->authorize('apps.update');
        $this->revealAppId = ReverbApp::findOrFail($id)->id;
    }

    public function closeReveal(): void
    {
        $this->revealAppId = null;
    }

    public function deleteApp(int $id): void
    {
        $this->authorize('apps.delete');
        ReverbApp::findOrFail($id)->delete();
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
        $apps = ReverbApp::query()
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('app_id', 'like', "%{$this->search}%");
            }))
            ->orderBy('name')
            ->paginate(15);

        $credentials = $this->revealAppId !== null && auth()->user()->can('apps.update')
            ? ReverbApp::find($this->revealAppId)?->only(['key', 'secret'])
            : null;

        return view('livewire.apps.index', compact('apps', 'credentials'))
            ->layout('components.layouts.app');
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'appId', 'allowedOrigins', 'maxConnections']);
        $this->pingInterval = 60;
        $this->activityTimeout = 30;
        $this->maxMessageSize = 10000;
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
            'appId' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:reverb_apps,app_id'],
            'allowedOrigins' => ['required', 'string', $this->originsRule()],
            'pingInterval' => ['required', 'integer', 'min:1'],
            'activityTimeout' => ['required', 'integer', 'min:1'],
            'maxMessageSize' => ['required', 'integer', 'min:1'],
            'maxConnections' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function editRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'allowedOrigins' => ['required', 'string', $this->originsRule()],
            'pingInterval' => ['required', 'integer', 'min:1'],
            'activityTimeout' => ['required', 'integer', 'min:1'],
            'maxMessageSize' => ['required', 'integer', 'min:1'],
            'maxConnections' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
