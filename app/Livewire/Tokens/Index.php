<?php

namespace App\Livewire\Tokens;

use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;

class Index extends Component
{
    public ?int $selectedUserId = null;

    public bool $showCreateModal = false;

    public string $tokenName = '';

    public ?string $expiresAt = null;

    public string $expiryPreset = 'never';

    public ?string $newTokenValue = null;

    public bool $showNewToken = false;

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user->can('tokens.manage') && ! $user->can('tokens.manage-own')) {
            abort(403);
        }

        if (! $user->can('tokens.manage')) {
            $this->selectedUserId = $user->id;
        }
    }

    protected function rules(): array
    {
        return [
            'tokenName' => ['required', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function updatedSelectedUserId(): void
    {
        if ($this->selectedUserId === null) {
            return;
        }

        $this->authorizeUserSelection($this->selectedUserId);
    }

    public function setExpiryPreset(string $preset): void
    {
        $this->expiryPreset = $preset;

        $this->expiresAt = match ($preset) {
            '1month' => Carbon::now()->addMonth()->format('Y-m-d'),
            '3months' => Carbon::now()->addMonths(3)->format('Y-m-d'),
            'never' => null,
            default => $this->expiresAt,
        };
    }

    public function updatedExpiresAt(): void
    {
        $this->expiryPreset = 'custom';
    }

    public function openCreateModal(): void
    {
        $this->authorizeUserSelection($this->selectedUserId);
        $this->reset(['tokenName', 'expiresAt', 'expiryPreset', 'newTokenValue', 'showNewToken']);
        $this->expiryPreset = 'never';
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->reset(['tokenName', 'expiresAt', 'expiryPreset', 'newTokenValue', 'showNewToken']);
    }

    public function createToken(): void
    {
        $this->authorizeUserSelection($this->selectedUserId);
        $this->validate();

        $user = User::findOrFail($this->selectedUserId);
        $expiry = $this->expiresAt ? Carbon::parse($this->expiresAt)->endOfDay() : null;
        $token = $user->createToken($this->tokenName, ['*'], $expiry);
        Audit::log('token.created', 'Created API token', $user, [
            'token' => $this->tokenName,
            'expires_at' => $expiry?->toDateTimeString(),
        ]);

        $this->newTokenValue = $token->plainTextToken;
        $this->showNewToken = true;
    }

    public function revokeToken(int $tokenId): void
    {
        $token = PersonalAccessToken::findOrFail($tokenId);
        $this->authorizeUserSelection($token->tokenable_id);
        $token->delete();

        if ($owner = User::find($token->tokenable_id)) {
            Audit::log('token.revoked', 'Revoked API token', $owner, ['token' => $token->name]);
        }
    }

    /**
     * A token acts as its owner, so managing another user's tokens also
     * requires being allowed to act as them; otherwise tokens.manage could
     * mint a token for a Super Admin.
     */
    private function authorizeUserSelection(?int $userId): void
    {
        $authUser = auth()->user();

        if ($userId === $authUser->id && $authUser->canAny(['tokens.manage', 'tokens.manage-own'])) {
            return;
        }

        $user = $userId === null ? null : User::find($userId);

        if ($user && $authUser->can('tokens.manage') && $authUser->mayActAs($user)) {
            return;
        }

        throw new AuthorizationException;
    }

    public function render()
    {
        $canManageAll = auth()->user()->can('tokens.manage');

        $users = $canManageAll
            ? User::with(['roles.permissions', 'permissions', 'teams'])->orderBy('name')->get()
                ->filter(fn (User $user) => $user->is(auth()->user()) || auth()->user()->mayActAs($user))
                ->values()
            : collect([auth()->user()]);

        // Only users listed above: those whose tokens this user may manage.
        $selectedUser = $this->selectedUserId
            ? $users->firstWhere('id', $this->selectedUserId)
            : null;

        $tokens = $selectedUser
            ? PersonalAccessToken::where('tokenable_id', $selectedUser->id)
                ->where('tokenable_type', User::class)
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return view('livewire.tokens.index', compact('users', 'tokens', 'selectedUser', 'canManageAll'))
            ->layout('components.layouts.app');
    }
}
