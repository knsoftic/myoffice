<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

/**
 * Editing a desk — the same rules as creating one (phase-19-23 §6.16).
 *
 * A separate class rather than a `Store…` reused on both routes, because the two differ in exactly
 * one respect and it is not in the rules: `authorize()` asks `update` against the row rather than
 * `create` against the class. {@see StoreTicketDepartmentRequest} already branches on the route
 * parameter for both, so this subclass exists to make the route table readable — `PUT` naming an
 * `Update…` request is one less thing to check when reading `routes/admin.php`.
 */
final class UpdateTicketDepartmentRequest extends StoreTicketDepartmentRequest {}
