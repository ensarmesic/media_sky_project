<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case ProjectManager = 'project_manager';
    case TeamMember = 'team_member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::ProjectManager => 'Voditelj projekta',
            self::TeamMember => 'Član tima',
            self::Viewer => 'Pregled',
        };
    }
}
