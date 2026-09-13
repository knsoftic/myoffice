<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Support\SettingsRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * Profile photo upload (route `account.avatar.store`, phase-01 §7).
 *
 * The type is decided by the file's **content**, not its name:
 *   · `image`      — Laravel reads the file with getimagesize(); a renamed .php never passes;
 *   · `mimetypes`  — matches the MIME type guessed from the bytes;
 *   · `mimes`      — keeps the extension honest as well.
 *
 * Absurd pixel dimensions are refused so an "image bomb" cannot be handed to the image pipeline
 * later.
 *
 * Both limits are governed by the Security settings, and can only ever be narrowed by them:
 *
 *   · **types** — the photo's own image types (IMAGE_TYPES) intersected with
 *     `security.allowed_file_types`, never including a script or executable extension
 *     (SettingsRegistry::uploadExtensions()). Taking `gif` off the list stops GIF photos; adding
 *     `php` to it adds nothing;
 *   · **size** — the smallest of this form's own 2 MB cap, `security.max_upload_mb` and PHP's own
 *     `upload_max_filesize` / `post_max_size` (SettingsRegistry::uploadKilobytes()), so the form never
 *     promises a size PHP would silently drop.
 */
final class UpdateAvatarRequest extends FormRequest
{
    /**
     * This form's own ceiling in kilobytes (2 MB). The settings can lower it, never raise it.
     */
    public const MAX_KILOBYTES = 2048;

    /**
     * The image types a profile photo may ever be: extension => MIME type.
     *
     * @var array<string, string>
     */
    public const IMAGE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensions = self::allowedExtensions();

        if ($extensions === []) {
            // The Security settings allow no image type at all: refuse with a sentence that says so,
            // rather than with an empty `mimes:` rule nobody can read.
            return [
                'avatar' => [
                    'required',
                    'file',
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        $fail('Profile photos are not among the upload types currently allowed.');
                    },
                ],
            ];
        }

        $mimeTypes = array_values(array_unique(array_map(
            static fn (string $extension): string => self::IMAGE_TYPES[$extension],
            $extensions,
        )));

        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimetypes:'.implode(',', $mimeTypes),
                'mimes:'.implode(',', $extensions),
                'max:'.self::maxKilobytes(),
                'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $types = self::readableTypes(self::allowedExtensions());

        return [
            'avatar.required' => 'Choose an image to upload.',
            'avatar.image' => 'That file is not an image.',
            'avatar.mimetypes' => sprintf('Upload a %s image.', $types),
            'avatar.mimes' => sprintf('Upload a %s image.', $types),
            'avatar.max' => sprintf('The image may not be larger than %s.', self::readableSize(self::maxKilobytes())),
            'avatar.dimensions' => 'The image must be between 32 and 4000 pixels on each side.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'photo',
        ];
    }

    /**
     * The extensions a profile photo may have right now, in IMAGE_TYPES order.
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return SettingsRegistry::uploadExtensions(
            array_keys(self::IMAGE_TYPES),
            self::setting('security.allowed_file_types'),
        );
    }

    /**
     * The largest profile photo accepted right now, in kilobytes.
     */
    public static function maxKilobytes(): int
    {
        return SettingsRegistry::uploadKilobytes(self::MAX_KILOBYTES, self::setting('security.max_upload_mb'));
    }

    /**
     * The upload field's `accept` attribute, from the types allowed right now.
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_values(array_unique(array_map(
            static fn (string $extension): string => self::IMAGE_TYPES[$extension],
            self::allowedExtensions(),
        ))));
    }

    /**
     * The upload field's hint — the same types and size the rules enforce, so the screen never
     * promises a GIF the Security settings refuse or a size PHP would drop.
     */
    public static function hint(): string
    {
        $extensions = self::allowedExtensions();

        if ($extensions === []) {
            return 'Profile photos are not among the upload types currently allowed.';
        }

        return sprintf('%s, up to %s', self::readableTypes($extensions), self::readableSize(self::maxKilobytes()));
    }

    /**
     * A setting, or null when settings cannot be read (fresh install) — which leaves this form's
     * own limits in force.
     */
    private static function setting(string $key): mixed
    {
        try {
            return settings_repo()->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ['jpg', 'jpeg', 'png'] => 'JPG, JPEG or PNG'.
     *
     * @param  list<string>  $extensions
     */
    private static function readableTypes(array $extensions): string
    {
        $readable = array_map('mb_strtoupper', $extensions);

        return match (count($readable)) {
            0 => 'permitted',
            1 => $readable[0],
            default => implode(', ', array_slice($readable, 0, -1)).' or '.end($readable),
        };
    }

    private static function readableSize(int $kilobytes): string
    {
        if ($kilobytes >= 1024) {
            $megabytes = intdiv($kilobytes * 10, 1024);

            return intdiv($megabytes, 10).($megabytes % 10 === 0 ? '' : '.'.($megabytes % 10)).' MB';
        }

        return $kilobytes.' KB';
    }
}
