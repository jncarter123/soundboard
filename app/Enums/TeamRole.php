<?php

namespace App\Enums;

/**
 * A user's role within a team. Each role gives, on the team's apps only,
 * the access that its global permissions give on every app.
 */
enum TeamRole: string
{
    case Owner = 'owner';
    case Maintainer = 'maintainer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The global permissions this role mirrors on the team's apps. Assigning
     * or removing the role requires holding all of them, so team membership
     * can never grant access the actor doesn't have.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Viewer => ['apps.read', 'metrics.read'],
            self::Maintainer => ['apps.read', 'metrics.read', 'apps.create', 'apps.update'],
            self::Owner => ['apps.read', 'metrics.read', 'apps.create', 'apps.update', 'apps.delete'],
        };
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function description(): string
    {
        return match ($this) {
            self::Viewer => 'See the team\'s apps and their metrics',
            self::Maintainer => 'Also create and edit apps, and view or regenerate credentials',
            self::Owner => 'Also delete apps',
        };
    }
}
