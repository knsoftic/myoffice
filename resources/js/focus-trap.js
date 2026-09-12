/**
 * x-trap — a dependency-free focus trap for modals and the mobile drawer.
 *
 * Usage:  <div x-trap="open"> ... </div>
 *
 * While the expression is truthy: focus moves into the container, Tab / Shift+Tab cycle
 * inside it, and focus returns to the previously focused element when it turns falsy.
 * Behaves like @alpinejs/focus's x-trap without adding the package.
 *
 * Modifiers:
 *   .noreturn  — do not restore focus to the opener on close
 *   .noscroll  — lock body scrolling while trapped
 */

const FOCUSABLE = [
    'a[href]',
    'area[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'details > summary:first-of-type',
    'iframe',
    'audio[controls]',
    'video[controls]',
    '[contenteditable]:not([contenteditable="false"])',
    '[tabindex]:not([tabindex^="-"])',
].join(',');

function isVisible(el) {
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

export function focusableWithin(container) {
    return Array.from(container.querySelectorAll(FOCUSABLE)).filter(
        (el) => isVisible(el) && !el.hasAttribute('aria-hidden'),
    );
}

export function registerFocusTrap(Alpine) {
    Alpine.directive('trap', (el, { expression, modifiers }, { evaluateLater, effect, cleanup }) => {
        const evaluate = evaluateLater(expression);

        let trapped = false;
        let opener = null;

        const onKeydown = (event) => {
            if (event.key !== 'Tab') {
                return;
            }

            const targets = focusableWithin(el);

            if (targets.length === 0) {
                event.preventDefault();
                el.focus();

                return;
            }

            const first = targets[0];
            const last = targets[targets.length - 1];
            const active = document.activeElement;

            if (!el.contains(active)) {
                event.preventDefault();
                first.focus();

                return;
            }

            if (event.shiftKey && active === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        };

        const activate = () => {
            if (trapped) {
                return;
            }

            trapped = true;
            opener = document.activeElement;

            if (modifiers.includes('noscroll')) {
                document.documentElement.classList.add('overflow-hidden');
            }

            // Wait for x-show / x-transition to make the node focusable.
            requestAnimationFrame(() => {
                const targets = focusableWithin(el);
                const preferred = el.querySelector('[x-trap-initial], [data-autofocus]');

                (preferred || targets[0] || el).focus({ preventScroll: true });
            });

            el.addEventListener('keydown', onKeydown);
        };

        const deactivate = () => {
            if (!trapped) {
                return;
            }

            trapped = false;
            el.removeEventListener('keydown', onKeydown);

            if (modifiers.includes('noscroll')) {
                document.documentElement.classList.remove('overflow-hidden');
            }

            if (!modifiers.includes('noreturn') && opener && typeof opener.focus === 'function') {
                opener.focus({ preventScroll: true });
            }

            opener = null;
        };

        effect(() => {
            evaluate((value) => (value ? activate() : deactivate()));
        });

        cleanup(deactivate);
    });
}

export default { registerFocusTrap, focusableWithin };
