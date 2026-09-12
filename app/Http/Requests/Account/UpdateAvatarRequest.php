<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Profile photo upload (route `account.avatar.store`, phase-01 §7).
 *
 * The type is decided by the file's **content**, not its name:
 *   · `image`      — Laravel reads the file with getimagesize(); a renamed .php never passes;
 *   · `mimetypes`  — matches the MIME type guessed from the bytes;
 *   · `mimes`      — keeps the extension honest as well.
 *
 * Size cap is 2 MB (`max` counts kilobytes), and absurd pixel dimensions are refused so an
 * "image bomb" cannot be handed to the image pipeline later.
 */
final class UpdateAvatarRequest extends FormRequest
{
    /**
     * Maximum upload size in kilobytes (2 MB).
     */
    public const MAX_KILOBYTES = 2048;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Choose an image to upload.',
            'avatar.image' => 'That file is not an image.',
            'avatar.mimetypes' => 'Upload a JPG, PNG, WEBP or GIF image.',
            'avatar.mimes' => 'Upload a JPG, PNG, WEBP or GIF image.',
            'avatar.max' => 'The image may not be larger than 2 MB.',
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
}
