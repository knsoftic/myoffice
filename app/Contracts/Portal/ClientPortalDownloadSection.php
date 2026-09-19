<?php

declare(strict_types=1);

namespace App\Contracts\Portal;

use App\Models\Crm\Client;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client portal section that streams files — `client.files.download`, `client.invoices.pdf` (phase-05 §7, §9.2,
 * D21).
 *
 * `download()` re-applies the section's ownership predicate before streaming (an attachment whose `visibility` is
 * `client` on one of this client's own projects; an issued invoice of this client), aborts **404** for anything
 * else, streams from the private disk as an attachment with `nosniff`, and writes the download to the audit log.
 * `$variant` selects a rendition where a record has several (`pdf`); a section with one file ignores it.
 */
interface ClientPortalDownloadSection extends ClientPortalSection
{
    public function download(Client $client, User $user, int $id, string $variant): Response;
}
