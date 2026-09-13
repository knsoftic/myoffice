<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One user's dashboard arrangement: the order they dragged the cards into, and the cards they
 * hid (phase-02 §1, §5).
 *
 * It lives in `users.preferences` under two dot paths and is read and written only through
 * `User::preference()` / `User::setPreferences()`, so two users can never share a layout and a
 * request body can never write an arbitrary preference tree.
 *
 * Everything here is defensive on purpose: the stored arrays are user input that survived a
 * previous release. Keys that no longer exist are ignored, a widget the user has never seen
 * (because its phase shipped after they last saved) appears at the end of its section rather
 * than vanishing, and a hidden key that is no longer registered is simply forgotten.
 */
final class Layout
{
    /** `users.preferences` dot path holding the ordered list of widget keys. */
    public const PREFERENCE_ORDER = 'dashboard.widget_order';

    /** `users.preferences` dot path holding the keys the user switched off. */
    public const PREFERENCE_HIDDEN = 'dashboard.hidden_widgets';

    /** Hard ceiling on a stored list, so a crafted payload cannot bloat the JSON column. */
    private const MAX_KEYS = 200;

    /**
     * @param  list<string>  $order
     * @param  list<string>  $hidden
     */
    private function __construct(
        public readonly array $order,
        public readonly array $hidden,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param  array<int|string, mixed>  $order
     * @param  array<int|string, mixed>  $hidden
     */
    public static function fromArray(array $order, array $hidden): self
    {
        return new self(self::keys($order), self::keys($hidden));
    }

    /**
     * The signed-in user's saved arrangement.
     */
    public static function fromUser(?User $user): self
    {
        if (! $user instanceof User) {
            return self::empty();
        }

        $order = $user->preference(self::PREFERENCE_ORDER, []);
        $hidden = $user->preference(self::PREFERENCE_HIDDEN, []);

        return self::fromArray(
            is_array($order) ? $order : [],
            is_array($hidden) ? $hidden : [],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function isHidden(string $key): bool
    {
        return in_array($key, $this->hidden, true);
    }

    public function isEmpty(): bool
    {
        return $this->order === [] && $this->hidden === [];
    }

    /**
     * Position of a key in the saved order, or null when the user has never placed it.
     */
    public function positionOf(string $key): ?int
    {
        $position = array_search($key, $this->order, true);

        return $position === false ? null : (int) $position;
    }

    /*
    |--------------------------------------------------------------------------
    | Applying
    |--------------------------------------------------------------------------
    */

    /**
     * Sort the descriptors the viewer is allowed to see into this layout.
     *
     * Cards the user has placed come first, in their order. Cards they have never seen follow,
     * in the order the widgets themselves declare (group sort, then widget sort, then title),
     * which is how a widget shipped by a later phase introduces itself: at the end, visible.
     *
     * Hiding is NOT applied here — `visible()` and `hiddenFrom()` split the result — because the
     * customise panel needs both halves.
     *
     * @param  Collection<string, WidgetDescriptor>  $descriptors
     * @return Collection<string, WidgetDescriptor>
     */
    public function arrange(Collection $descriptors): Collection
    {
        return $descriptors
            ->sortBy(
                function (WidgetDescriptor $descriptor): array {
                    $position = $this->positionOf($descriptor->key);

                    return [
                        // Placed cards before unplaced ones, whatever their group.
                        $position === null ? 1 : 0,
                        $position ?? 0,
                        $descriptor->groupSort,
                        $descriptor->sort,
                        $descriptor->title,
                    ];
                },
                SORT_REGULAR,
            )
            ->values()
            ->keyBy(static fn (WidgetDescriptor $descriptor): string => $descriptor->key);
    }

    /**
     * The arranged, *shown* cards.
     *
     * @param  Collection<string, WidgetDescriptor>  $descriptors
     * @return Collection<string, WidgetDescriptor>
     */
    public function visible(Collection $descriptors): Collection
    {
        return $this->arrange($descriptors)
            ->reject(fn (WidgetDescriptor $descriptor): bool => $this->isHidden($descriptor->key));
    }

    /**
     * The cards the user switched off — the restore tray in customise mode.
     *
     * @param  Collection<string, WidgetDescriptor>  $descriptors
     * @return Collection<string, WidgetDescriptor>
     */
    public function hiddenFrom(Collection $descriptors): Collection
    {
        return $this->arrange($descriptors)
            ->filter(fn (WidgetDescriptor $descriptor): bool => $this->isHidden($descriptor->key));
    }

    /**
     * Drop keys that are not (any longer) registered, or that this viewer may not see.
     *
     * Called before saving, so a user who is shown fifteen cards cannot post a layout that
     * silently stores the key of a sixteenth their permissions exclude.
     *
     * @param  list<string>  $allowed
     */
    public function restrictTo(array $allowed): self
    {
        $allowed = array_values(array_unique(array_map('strval', $allowed)));

        return new self(
            array_values(array_filter($this->order, static fn (string $key): bool => in_array($key, $allowed, true))),
            array_values(array_filter($this->hidden, static fn (string $key): bool => in_array($key, $allowed, true))),
        );
    }

    /**
     * The two dot paths, ready for `User::setPreferences()` — one save, one UPDATE.
     *
     * An empty list is stored as an empty array rather than dropped, so "I hid nothing on
     * purpose" and "I have never saved a layout" stay distinguishable.
     *
     * @return array<string, list<string>>
     */
    public function toPreferences(): array
    {
        return [
            self::PREFERENCE_ORDER => $this->order,
            self::PREFERENCE_HIDDEN => $this->hidden,
        ];
    }

    /**
     * @return array{order: list<string>, hidden: list<string>}
     */
    public function toArray(): array
    {
        return ['order' => $this->order, 'hidden' => $this->hidden];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Scrub a stored or posted list into a clean, unique, bounded list of widget keys.
     *
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private static function keys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $key = trim((string) $value);

            if ($key === '' || in_array($key, $keys, true)) {
                continue;
            }

            // Same shape the registry enforces on key(): anything else cannot be a widget.
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                continue;
            }

            $keys[] = $key;

            if (count($keys) >= self::MAX_KEYS) {
                break;
            }
        }

        return $keys;
    }
}
