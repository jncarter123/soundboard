<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

/**
 * Creating, renaming, and deleting teams takes teams.manage. A team's
 * owners can also see and manage its members, without that permission.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('teams.read') || $this->ownsAny($user);
    }

    public function view(User $user, Team $team): bool
    {
        return $user->can('teams.read') || $user->teamRole($team->id) === TeamRole::Owner;
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $user->can('teams.manage') || $user->teamRole($team->id) === TeamRole::Owner;
    }

    private function ownsAny(User $user): bool
    {
        return $user->teams->contains(fn (Team $team) => $user->teamRole($team->id) === TeamRole::Owner);
    }
}
