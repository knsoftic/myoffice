<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\Models\Crm\Client;

/**
 * The client panel home (phase-05 §6.9 `ClientPortalService::dashboard()`, §8.10).
 *
 * Every card comes from a registered, permitted section's `badgeCount()` — a card whose section is not
 * registered is simply absent (never a zero). `documentsCount` and `unreadNotifications` are Phase 5's own
 * figures and are null when their section is not visible to the user.
 */
final readonly class DashboardData
{
    /**
     * @param  list<array{key: string, label: string, icon: string, count: int|null, route: string|null}>  $cards
     */
    public function __construct(
        public Client $client,
        public string $clientName,
        public array $cards,
        public ?int $documentsCount = null,
        public ?int $unreadNotifications = null,
    ) {}

    public function isSettingUp(): bool
    {
        return $this->cards === [];
    }
}
