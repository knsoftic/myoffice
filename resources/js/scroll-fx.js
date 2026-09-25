/**
 * Scroll effects for the public site: one-shot entrance reveals, and continuous
 * scroll-linked depth.
 *
 * **This module never decides whether effects run.** `site/partials/fx-script.blade.php`
 * already made that call before first paint — JavaScript present, IntersectionObserver
 * available, reduced motion not requested — and recorded it as `.fx-on` on <html>. Every
 * CSS declaration that hides anything is scoped to that class, so if this file never
 * loads, or throws on its first line, the page is simply the page. Re-deciding here would
 * mean two sources of truth for "is anything hidden right now", and the failure mode of
 * their disagreeing is a blank marketing site.
 *
 * **Two systems, deliberately separate.**
 *
 *   `[data-fx]`         a one-shot reveal. Observed, revealed, unobserved. Nothing about it
 *                       is recomputed afterwards — an element that has arrived is finished.
 *
 *   `[data-fx-parallax]`  continuous. Its `--fx-p` is rewritten as the page moves, so it
 *   `[data-fx-float]`     costs work on every frame it is on screen.
 *
 * The second is why an IntersectionObserver drives the parallax set too, rather than a
 * querySelectorAll on each frame. Only elements actually in view are in the live set, so a
 * long page with forty layers still only measures the handful the reader can see. The
 * alternative — read every element every frame — is the version of this that makes a
 * marketing page scroll badly on a mid-range phone.
 */

const REVEAL_SELECTOR = '[data-fx]';
const PARALLAX_SELECTOR = '[data-fx-parallax], [data-fx-float]';

/** How far past the bottom edge an element is revealed: a little before it is fully in. */
const REVEAL_MARGIN = '0px 0px -12% 0px';

function clamp(value, min, max) {
    return value < min ? min : value > max ? max : value;
}

/**
 * Where this element sits in the viewport, as -1 (one screen below the middle) through
 * 0 (dead centre) to +1 (one screen above).
 *
 * Measured from the element's centre rather than its top, so a tall block and a short one
 * reach the same value at the same visual moment — anchoring on the top makes a full-height
 * hero finish its travel while it is still filling the screen.
 */
function progressOf(rect, viewportHeight) {
    const half = viewportHeight / 2;
    const centre = rect.top + rect.height / 2;

    return clamp((half - centre) / half, -1, 1);
}

export function registerScrollFx() {
    const root = document.documentElement;

    // The gate already ran. Not our decision to second-guess.
    if (!root.classList.contains('fx-on')) {
        return;
    }

    /* ------------------------------------------------------------------ reveals */

    /**
     * Hand the element back once it has arrived.
     *
     * **The entrance must not outlive itself.** While `data-fx` is present the element carries a
     * 700ms transition on `transform` and a `transform: none` that outranks Tailwind's own
     * utilities — `.fx-on [data-fx].fx-in` is two classes and an attribute against `.hover\:…:hover`'s
     * one class and a pseudo-class. A card with `hover:-translate-y-0.5` would therefore stop
     * lifting on hover the moment it finished appearing, and anything that did still move would
     * take 700ms to do it. Both are the effect quietly breaking the component it decorated.
     *
     * So the attribute is removed when the transition ends, which drops the whole rule set — the
     * transition, the override, the `will-change` — and returns a perfectly ordinary element.
     *
     * The timeout is the safety net, not the mechanism: `transitionend` never fires for an element
     * that was already at its final value, was hidden when revealed, or sits in a tab the browser
     * has throttled, and an element that never hears back would keep the override for ever.
     */
    function release(el) {
        let done = false;

        const finish = () => {
            if (done) {
                return;
            }

            done = true;
            el.removeAttribute('data-fx');
            el.removeAttribute('data-fx-delay');
        };

        el.addEventListener('transitionend', finish, { once: true });

        // 700ms transition + 480ms of the longest stagger, with room to spare.
        window.setTimeout(finish, 1600);
    }

    const revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('fx-in');
                release(entry.target);

                // One-shot: an element that has arrived never leaves again, and keeping it
                // observed would pay for a callback on every future crossing for nothing.
                revealObserver.unobserve(entry.target);
            });
        },
        { rootMargin: REVEAL_MARGIN, threshold: 0.01 },
    );

    document.querySelectorAll(REVEAL_SELECTOR).forEach((el) => revealObserver.observe(el));

    /* ----------------------------------------------------------------- parallax */

    /** Elements currently on screen, and therefore worth measuring this frame. */
    const live = new Set();
    let frame = null;

    function paint() {
        frame = null;

        const viewportHeight = window.innerHeight || 1;

        live.forEach((el) => {
            const rect = el.getBoundingClientRect();

            el.style.setProperty('--fx-p', progressOf(rect, viewportHeight).toFixed(4));
        });
    }

    function schedule() {
        // Coalesce to one paint per frame: scroll fires far more often than the screen
        // refreshes, and writing the same property six times between two frames is six
        // style recalculations the reader cannot see.
        if (frame === null && live.size > 0) {
            frame = window.requestAnimationFrame(paint);
        }
    }

    const parallaxObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    live.add(entry.target);
                } else {
                    live.delete(entry.target);
                }
            });

            schedule();
        },
        // A generous margin so a layer is already in the live set, and already positioned,
        // by the time its first pixel is visible — otherwise it snaps into place on entry.
        { rootMargin: '20% 0px 20% 0px' },
    );

    document.querySelectorAll(PARALLAX_SELECTOR).forEach((el) => parallaxObserver.observe(el));

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });

    // Position everything that is already on screen at load, before the first scroll.
    schedule();

    /* --------------------------------------------------- reduced motion, later */

    /*
    | Somebody can switch reduced motion on while the page is open — a system setting
    | changed in another window, or an OS focus mode. Honouring it only at load would
    | leave the one visitor who just asked for less movement watching the most.
    |
    | Dropping `.fx-on` un-hides every unrevealed element through CSS alone, so nothing
    | needs unwinding here; the observers are disconnected so they stop costing anything.
    */
    const query = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    if (query && typeof query.addEventListener === 'function') {
        query.addEventListener('change', (event) => {
            if (!event.matches) {
                return;
            }

            root.classList.remove('fx-on');
            revealObserver.disconnect();
            parallaxObserver.disconnect();
            live.clear();

            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
        });
    }
}
