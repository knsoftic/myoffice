/**
 * Reusable Alpine.data() behaviours for the x-ui.* components.
 *
 * Keeping the logic here (rather than inline in Blade) means every dropdown and modal in the
 * product shares one keyboard contract, and the markup stays readable.
 */

import { focusableWithin } from './focus-trap';

/** Keyboard-navigable dropdown menu — used by x-ui.dropdown and the user menu. */
function uiDropdown() {
    return {
        open: false,

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
        },

        close(refocus = true) {
            if (!this.open) {
                return;
            }

            this.open = false;

            if (!refocus || !this.$refs.trigger) {
                return;
            }

            // The trigger ref may be a wrapper element; focus the real control inside it.
            const trigger = this.$refs.trigger;
            const focusable = trigger.matches('button, a[href], [tabindex]')
                ? trigger
                : trigger.querySelector('button, a[href], [tabindex]');

            focusable?.focus({ preventScroll: true });
        },

        /** Menu items, in DOM order. */
        items() {
            if (!this.$refs.menu) {
                return [];
            }

            return Array.from(
                this.$refs.menu.querySelectorAll('[role="menuitem"]:not([disabled]):not([aria-disabled="true"])'),
            );
        },

        focusAt(index) {
            const items = this.items();

            if (items.length === 0) {
                return;
            }

            const target = items[(index + items.length) % items.length];

            target.focus({ preventScroll: true });
        },

        currentIndex() {
            return this.items().indexOf(document.activeElement);
        },

        focusFirst() {
            this.$nextTick(() => this.focusAt(0));
        },

        focusLast() {
            this.$nextTick(() => this.focusAt(this.items().length - 1));
        },

        focusNext() {
            this.focusAt(this.currentIndex() + 1);
        },

        focusPrev() {
            const index = this.currentIndex();

            this.focusAt(index === -1 ? this.items().length - 1 : index - 1);
        },

        /** ArrowDown / ArrowUp / Enter open the menu from the trigger. */
        onTriggerKeydown(event) {
            if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                this.show();
                this.focusFirst();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.show();
                this.focusLast();
            }
        },

        /** Arrow / Home / End / Escape / Tab inside the open menu. */
        onMenuKeydown(event) {
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    this.focusNext();
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    this.focusPrev();
                    break;
                case 'Home':
                    event.preventDefault();
                    this.focusAt(0);
                    break;
                case 'End':
                    event.preventDefault();
                    this.focusAt(this.items().length - 1);
                    break;
                case 'Escape':
                    event.preventDefault();
                    this.close();
                    break;
                case 'Tab':
                    this.close(false);
                    break;
                default:
                    break;
            }
        },
    };
}

/**
 * Modal dialog. Opens on a window `open-modal` event carrying its name, so any button
 * anywhere can drive it: $dispatch('open-modal', 'delete-user').
 */
function uiModal(name = null, closeable = true) {
    return {
        name,
        closeable,
        open: false,

        show() {
            this.open = true;
        },

        hide(force = false) {
            if (!this.closeable && !force) {
                return;
            }

            this.open = false;
        },

        onWindowOpen(event) {
            if (!this.name || event.detail === this.name || event.detail?.name === this.name) {
                this.show();
            }
        },

        onWindowClose(event) {
            if (!event.detail || event.detail === this.name || event.detail?.name === this.name) {
                this.hide(true);
            }
        },
    };
}

/** Destructive-action confirm dialog with an optional "type the name" gate. */
function uiConfirm(requireText = null) {
    return {
        open: false,
        typed: '',
        submitting: false,
        requireText,

        show() {
            this.typed = '';
            this.submitting = false;
            this.open = true;
        },

        hide() {
            this.open = false;
        },

        get confirmed() {
            if (!this.requireText) {
                return true;
            }

            return this.typed.trim().toLowerCase() === String(this.requireText).trim().toLowerCase();
        },

        submit() {
            if (!this.confirmed || this.submitting) {
                return;
            }

            this.submitting = true;
            this.$refs.form.submit();
        },
    };
}

/** Drag-and-drop file field with an image preview. */
function uiFile(multiple = false) {
    return {
        multiple,
        dragging: false,
        files: [],

        browse() {
            this.$refs.input.click();
        },

        onDrop(event) {
            this.dragging = false;

            const dropped = event.dataTransfer?.files;

            if (!dropped || dropped.length === 0) {
                return;
            }

            this.$refs.input.files = dropped;
            this.read(dropped);
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        onChange(event) {
            this.read(event.target.files);
        },

        read(fileList) {
            this.files = Array.from(fileList || []).map((file) => ({
                name: file.name,
                size: file.size,
                type: file.type,
                url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            }));
        },

        reset() {
            this.files.forEach((file) => file.url && URL.revokeObjectURL(file.url));
            this.files = [];
            this.$refs.input.value = '';
        },

        humanSize(bytes) {
            if (!bytes) {
                return '0 B';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

            return `${(bytes / 1024 ** index).toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
        },
    };
}

/** Local (non-navigating) tab panels. */
function uiTabs(initial = null) {
    return {
        active: initial,

        select(key) {
            this.active = key;
        },

        is(key) {
            return this.active === key;
        },

        onKeydown(event) {
            const tabs = Array.from(this.$el.querySelectorAll('[role="tab"]'));
            const index = tabs.indexOf(document.activeElement);

            if (index === -1) {
                return;
            }

            const moves = { ArrowRight: 1, ArrowLeft: -1 };

            if (moves[event.key]) {
                event.preventDefault();
                tabs[(index + moves[event.key] + tabs.length) % tabs.length].focus();
            }
        },
    };
}

/** Auto-submitting filter bar (search + selects), debounced. */
function uiFilterBar() {
    return {
        submit() {
            this.$refs.form?.requestSubmit
                ? this.$refs.form.requestSubmit()
                : this.$refs.form?.submit();
        },
    };
}

export function registerComponents(Alpine) {
    Alpine.data('uiDropdown', uiDropdown);
    Alpine.data('uiModal', uiModal);
    Alpine.data('uiConfirm', uiConfirm);
    Alpine.data('uiFile', uiFile);
    Alpine.data('uiTabs', uiTabs);
    Alpine.data('uiFilterBar', uiFilterBar);

    // Exposed for ad-hoc scripts that need the same focusable query.
    Alpine.magic('focusables', () => (container) => focusableWithin(container));
}

export default { registerComponents };
