<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Modules;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: `module` (phase-01 §6, D5).
 *
 *   ->middleware('module:projects')          // 403 while the projects module is disabled
 *   ->middleware('module:invoices,payments') // every listed module must be enabled
 *
 * A disabled module is closed for everyone, Super Admin included (the matching Gate::before
 * rule denies the permissions, this middleware denies the routes), while its data stays intact.
 * Core modules can never be disabled, so they always pass.
 */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            $slug = trim($module);

            if ($slug === '') {
                continue;
            }

            // Modules::enabled() already returns true for core modules and unknown slugs.
            if (Modules::enabled($slug)) {
                continue;
            }

            abort(
                Response::HTTP_FORBIDDEN,
                sprintf('The %s module is currently disabled.', $this->nameFor($slug))
            );
        }

        return $next($request);
    }

    /**
     * Human readable module name for the error message.
     */
    private function nameFor(string $slug): string
    {
        $module = PermissionRegistry::module($slug);

        if (is_array($module) && isset($module['name']) && is_string($module['name'])) {
            return $module['name'];
        }

        return Str::headline($slug);
    }
}
