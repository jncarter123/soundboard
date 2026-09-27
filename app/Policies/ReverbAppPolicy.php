<?php

namespace App\Policies;

use App\Models\ReverbApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A global permission (apps.update, metrics.read, ...) covers every app.
 * Without it, a user gets the same access on their teams' apps through
 * their team role. Apps outside the user's reach are reported as not found,
 * so their existence isn't revealed.
 */
class ReverbAppPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('apps.read') || $user->teams->isNotEmpty();
    }

    public function view(User $user, ReverbApp $app): Response
    {
        return $this->check($user, $app, 'apps.read');
    }

    /**
     * Creating an app with no team needs apps.create; creating one in a team
     * needs apps.create or a team role that grants it.
     */
    public function create(User $user, ?Team $team = null): bool
    {
        return $user->can('apps.create') || ($team && $user->teamRole($team->id)?->grants('apps.create'));
    }

    public function update(User $user, ReverbApp $app): Response
    {
        return $this->check($user, $app, 'apps.update');
    }

    public function delete(User $user, ReverbApp $app): Response
    {
        return $this->check($user, $app, 'apps.delete');
    }

    /**
     * Moving an app between teams (or out of one) changes who can reach it,
     * so it takes the global permission.
     */
    public function changeTeam(User $user, ReverbApp $app): bool
    {
        return $user->can('apps.update');
    }

    public function viewAnyMetrics(User $user): bool
    {
        return $user->can('metrics.read') || $user->teams->isNotEmpty();
    }

    private function check(User $user, ReverbApp $app, string $permission): Response
    {
        $role = $user->teamRole($app->team_id);

        if ($user->can($permission) || $role?->grants($permission)) {
            return Response::allow();
        }

        return $role || $user->can('apps.read') ? Response::deny() : Response::denyAsNotFound();
    }
}
