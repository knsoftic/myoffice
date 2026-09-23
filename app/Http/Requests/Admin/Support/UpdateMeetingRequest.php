<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

/**
 * Editing a meeting — the same fields as booking one (phase-19-23 §6.17).
 *
 * The guest list is **not** edited here: adding and removing people are their own routes, gated on
 * `meetings.assign` rather than `meetings.edit`, and a removal takes a reason. Folding them into
 * this form would mean somebody editing a title could silently drop three people from the room.
 * {@see StoreMeetingRequest::participants()} still exists on this subclass and is simply not called
 * by `MeetingController::update()`.
 */
final class UpdateMeetingRequest extends StoreMeetingRequest {}
