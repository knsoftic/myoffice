<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\NonPublicSettingException;

/**
 * The only way a public (`site.*`) view or component reads a setting (phase-03 §5.3, INV-10, FT-42).
 *
 *   site_setting('company.name', '');
 *
 * The registry decides, never the `settings.is_public` column: a hand edit to that column cannot open a
 * secret to the website, and a key the registry does not declare is refused as well. The value comes
 * from the request-scoped SettingsRepository payload, so a public page costs no query per key.
 */
final class SiteSettings
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The typed value of a public setting, the caller's default, or the registry default.
     *
     * @throws NonPublicSettingException when the key is undeclared or not `public => true`
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $field = SettingsRegistry::field($key);

        if ($field === null || $field['public'] !== true) {
            throw NonPublicSettingException::forKey($key);
        }

        return $this->settings->get($key, $default ?? $field['default']);
    }

    /**
     * Is this key readable by the public website?
     */
    public function isPublic(string $key): bool
    {
        return (SettingsRegistry::field($key)['public'] ?? false) === true;
    }
}
