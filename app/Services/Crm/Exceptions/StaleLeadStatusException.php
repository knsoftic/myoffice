<?php

declare(strict_types=1);

namespace App\Services\Crm\Exceptions;

use App\Enums\LeadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The compare-and-swap of a status move failed (phase-05 §6.1 `changeStatus()`, R-2, test 14): the lead no longer
 * holds the `expected_from_status` the board sent, because a colleague moved it. HTTP **409** carrying the
 * current status; nothing was written.
 */
final class StaleLeadStatusException extends RuntimeException
{
    public function __construct(
        public readonly LeadStatus $current,
        public readonly LeadStatus $expected,
    ) {
        parent::__construct(sprintf(
            'This lead was moved to %s by someone else. The board has been refreshed.',
            $current->label(),
        ));
    }

    /**
     * @return array{current_status: string, expected_status: string}
     */
    public function context(): array
    {
        return [
            'current_status' => $this->current->value,
            'expected_status' => $this->expected->value,
        ];
    }

    /**
     * Rendered by Laravel's handler when a controller does not catch it.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage(), ...$this->context()], 409);
        }

        return redirect()->back()
            ->withErrors(['to_status' => $this->getMessage()])
            ->with('toast', ['type' => 'error', 'message' => $this->getMessage()]);
    }
}
