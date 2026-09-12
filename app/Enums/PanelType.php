<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The five panel entry points (roles.panel). Each one owns a route prefix,
 * a route-name prefix and a dashboard landing route.
 */
enum PanelType: string
{
    use HasOptions;

    case Admin = 'admin';
    case Collaborator = 'collaborator';
    case Student = 'student';
    case Teacher = 'teacher';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Collaborator => 'Collaborator',
            self::Student => 'Student',
            self::Teacher => 'Teacher',
            self::Client => 'Client',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'indigo',
            self::Collaborator => 'amber',
            self::Student => 'emerald',
            self::Teacher => 'rose',
            self::Client => 'slate',
        };
    }

    /**
     * Named route a user of this panel is sent to after login.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            self::Collaborator => 'collaborator.dashboard',
            self::Student => 'student.dashboard',
            self::Teacher => 'teacher.dashboard',
            self::Client => 'client.dashboard',
        };
    }

    /**
     * URL prefix (also the route-name prefix) the panel is registered under.
     */
    public function routePrefix(): string
    {
        return match ($this) {
            self::Admin => 'admin',
            self::Collaborator => 'collaborator',
            self::Student => 'student',
            self::Teacher => 'teacher',
            self::Client => 'client',
        };
    }

    /**
     * The internal staff panel, used as the default for admin-side roles.
     */
    public static function staffPanel(): self
    {
        return self::Admin;
    }
}
