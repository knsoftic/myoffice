<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A phase-04 business rule refused the act (phase-04 §6: "domain exception → 422 toast").
 *
 * These are the rules that hold whatever the caller's permissions — a Super Admin included: a category
 * with services cannot be deleted without a reassign target, a gallery holds at most 20 images, a
 * pending review cannot be featured, a candidate cannot jump from `new` to `interview`, a closed job
 * accepts no application.
 *
 * It **is** a `ValidationException`, on purpose. Laravel already renders one correctly everywhere this
 * phase is called from: a 422 with an `errors` bag for a `fetch()` / JSON request, a redirect back with
 * the error on the named field for a browser form, and nothing in `failed_jobs` for a queued caller that
 * catches it. A controller that wants a toast as well catches it and adds one; a controller that forgets
 * still answers with the right status instead of a stack trace. It is thrown inside the service's
 * transaction, so the refused act leaves nothing behind.
 */
class ContentRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    public static function termHasChildren(string $label, int $count, string $childNoun): static
    {
        return static::refuse('reassign_to', sprintf(
            '"%s" still has %d %s. Choose another category to move %s to before deleting it.',
            $label,
            $count,
            $count === 1 ? $childNoun : $childNoun.'s',
            $count === 1 ? 'it' : 'them',
        ));
    }

    public static function invalidReassignTarget(): static
    {
        return static::refuse('reassign_to', 'Choose a different, existing entry of the same kind to move the items to.');
    }

    public static function foreignIds(string $field = 'ids'): static
    {
        return static::refuse($field, 'The list contains an entry that does not belong here. Refresh the page and try again.');
    }

    public static function notAttached(string $field = 'media_asset_id'): static
    {
        return static::refuse($field, 'That image is not part of this project\'s gallery.');
    }

    public static function galleryFull(int $max, int $current, int $adding): static
    {
        return static::refuse('images', sprintf(
            'A project gallery holds at most %d images. It has %d and this would add %d — nothing was uploaded.',
            $max,
            $current,
            $adding,
        ));
    }

    public static function altTextRequired(int $missing): static
    {
        return static::refuse('status', sprintf(
            'Add alt text to %d gallery %s before publishing this project.',
            $missing,
            $missing === 1 ? 'image' : 'images',
        ));
    }

    public static function transitionNotAllowed(string $from, string $to, string $field = 'status'): static
    {
        return static::refuse($field, sprintf('It cannot move from "%s" to "%s".', $from, $to));
    }

    public static function cannotSchedule(string $label): static
    {
        return static::refuse('status', sprintf('%s cannot be scheduled — publish it or keep it as a draft.', $label));
    }

    public static function reasonRequired(string $field = 'reason'): static
    {
        return static::refuse($field, 'A reason is required.');
    }

    public static function notApproved(): static
    {
        return static::refuse('is_featured', 'Only an approved review can be featured.');
    }

    public static function publishRequirements(): static
    {
        return static::refuse('status', 'A name and a permalink are required before publishing.');
    }

    public static function interviewSlotRequired(): static
    {
        return static::refuse('interview_at', 'An interview needs a future date and time and a mode.');
    }

    public static function duplicateApplication(): static
    {
        return static::refuse('email', 'You have already applied for this position.');
    }

    public static function unknownSocialPlatform(string $key): static
    {
        return static::refuse('social_links', sprintf('"%s" is not a supported social platform.', $key));
    }

    public static function invalidUrl(string $field): static
    {
        return static::refuse($field, 'Enter a full web address starting with http:// or https://.');
    }

    public static function videoHostNotAllowed(string $field = 'video_url'): static
    {
        return static::refuse($field, 'Only YouTube and Vimeo video links are accepted.');
    }

    public static function tooManyItems(string $field, int $max): static
    {
        return static::refuse($field, sprintf('At most %d entries are allowed.', $max));
    }

    public static function slugUnavailable(): static
    {
        return static::refuse('slug', 'A unique permalink could not be generated. Enter one by hand.');
    }

    public static function salaryRange(): static
    {
        return static::refuse('salary_max', 'The maximum salary cannot be lower than the minimum.');
    }

    public static function uploadRefused(string $field, string $message): static
    {
        return static::refuse($field, $message);
    }
}
