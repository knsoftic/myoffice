<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The eleven things the command palette can find (phase-19-23 §3.5, §108, [D-23-3]).
 *
 * **§108's list, verbatim and in its order.** Three screens read this one declaration — the palette
 * groups by it, the filter chips are built from it, and `GlobalSearchRegistry` keys its providers on
 * it — so a twelfth entity is one case here plus one provider, and never a list to keep in step in
 * three places.
 *
 * **`module()` and `permission()` are on the enum rather than on the provider**, because
 * `GlobalSearchRegistry::availableTo()` has to answer "may this person search students?" *before*
 * constructing a provider, and `reports.global_search_entities` stores these values. A provider that
 * owned its own gate would mean the registry could not filter the list it offers.
 *
 * The permission is the module's `view_any` in every case but one: a **student** searching is not
 * exercising `students.view_any` — their provider narrows to their own row — so the gate is the
 * module's `view`, and the provider's own scope does the rest. That split is why `permission()`
 * returns the *minimum* to reach the provider at all, never the answer to what it may return.
 */
enum SearchEntityType: string
{
    use HasOptions;

    case Client = 'client';
    case Employee = 'employee';
    case Collaborator = 'collaborator';
    case Student = 'student';
    case Teacher = 'teacher';
    case Course = 'course';
    case Lead = 'lead';
    case Project = 'project';
    case Task = 'task';
    case Invoice = 'invoice';
    case Ticket = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Clients',
            self::Employee => 'Employees',
            self::Collaborator => 'Collaborators',
            self::Student => 'Students',
            self::Teacher => 'Teachers',
            self::Course => 'Courses',
            self::Lead => 'Leads',
            self::Project => 'Projects',
            self::Task => 'Tasks',
            self::Invoice => 'Invoices',
            self::Ticket => 'Tickets',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Client => 'indigo',
            self::Employee => 'violet',
            self::Collaborator => 'amber',
            self::Student => 'emerald',
            self::Teacher => 'teal',
            self::Course => 'cyan',
            self::Lead => 'orange',
            self::Project => 'blue',
            self::Task => 'sky',
            self::Invoice => 'rose',
            self::Ticket => 'slate',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Client => 'briefcase',
            self::Employee => 'identification',
            self::Collaborator => 'users',
            self::Student => 'academic-cap',
            self::Teacher => 'presentation-chart-bar',
            self::Course => 'book-open',
            self::Lead => 'sparkles',
            self::Project => 'rectangle-stack',
            self::Task => 'check-circle',
            self::Invoice => 'document-text',
            self::Ticket => 'lifebuoy',
        };
    }

    /** The module slug that gates this entity. A disabled module removes it from the palette. */
    public function module(): string
    {
        return match ($this) {
            self::Client => 'clients',
            self::Employee => 'employees',
            self::Collaborator => 'collaborators',
            self::Student => 'students',
            self::Teacher => 'teachers',
            self::Course => 'courses',
            self::Lead => 'leads',
            self::Project => 'projects',
            self::Task => 'tasks',
            self::Invoice => 'invoices',
            self::Ticket => 'support_tickets',
        };
    }

    /**
     * The least a person must hold to reach this provider at all.
     *
     * Never the answer to *what* it returns — every provider narrows further, and the two questions
     * are deliberately separate. A teacher holds `students.view` and sees the students of their own
     * batches; a student holds it and sees themselves.
     */
    public function permission(): string
    {
        return $this->module().'.view';
    }

    /**
     * The order the palette groups appear in, and the tie-break when scores are equal.
     *
     * People and money first, because that is what somebody typing into a search box at speed is
     * almost always looking for; tasks last because a task is usually reached from its project.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Client => 10,
            self::Student => 20,
            self::Project => 30,
            self::Invoice => 40,
            self::Lead => 50,
            self::Employee => 60,
            self::Collaborator => 70,
            self::Teacher => 80,
            self::Course => 90,
            self::Ticket => 100,
            self::Task => 110,
        };
    }
}
