<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Requests\Account\UpdateAvatarRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Services\Auth\AvatarService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The user's own profile (routes `account.profile*`, phase-01 §7).
 *
 * Writable fields are fixed by UpdateProfileRequest — name, phone, whatsapp, locale, timezone.
 * The photo has its own two endpoints so an upload failure never discards the rest of the form,
 * and so removing a photo is a plain DELETE behind a confirm dialog.
 */
final class ProfileController extends AccountController
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function edit(): View
    {
        $user = $this->currentUser();

        return $this->render('account.profile', self::TAB_PROFILE, [
            'user' => $user,
            'locales' => UpdateProfileRequest::localeOptions($user->locale),
            'timezones' => $this->timezoneOptions(),
            'hasAvatar' => $this->avatars->has($user),
            'maxAvatarKilobytes' => UpdateAvatarRequest::MAX_KILOBYTES,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $this->currentUser();

        $user->fill($request->validated())->save();

        return redirect()
            ->route('account.profile')
            ->with('toast', ['type' => 'success', 'message' => 'Profile updated.']);
    }

    /**
     * Replace the profile photo. The file has already been validated by content.
     */
    public function storeAvatar(UpdateAvatarRequest $request): RedirectResponse
    {
        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            return back()->with('toast', ['type' => 'error', 'message' => 'No image was received.']);
        }

        $this->avatars->store($this->currentUser(), $file);

        return redirect()
            ->route('account.profile')
            ->with('toast', ['type' => 'success', 'message' => 'Profile photo updated.']);
    }

    /**
     * Drop the photo and fall back to the generated initials avatar.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $this->currentUser();

        if (! $this->avatars->has($user)) {
            return redirect()
                ->route('account.profile')
                ->with('toast', ['type' => 'info', 'message' => 'There is no photo to remove.']);
        }

        $this->avatars->delete($user);

        return redirect()
            ->route('account.profile')
            ->with('toast', ['type' => 'success', 'message' => 'Profile photo removed.']);
    }

    /**
     * Every timezone, labelled with its current UTC offset.
     *
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new DateTimeZone($identifier))->getOffset($now);
            $absolute = abs($offset);

            $options[$identifier] = sprintf(
                '%s (UTC%s%02d:%02d)',
                str_replace('_', ' ', $identifier),
                $offset < 0 ? '-' : '+',
                intdiv($absolute, 3600),
                intdiv($absolute % 3600, 60),
            );
        }

        return $options;
    }
}
