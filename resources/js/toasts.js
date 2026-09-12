/**
 * Toast queue.
 *
 * Server side:  session()->flash('toast', ['type' => 'success', 'message' => '...'])
 *               rendered by <x-ui.toast /> which hydrates this store on boot.
 *
 * Client side:  window.toasts.push({ type: 'error', message: 'Upload failed' })
 *               window.toasts.success('Saved'), .error(), .warning(), .info()
 */

const DEFAULT_TIMEOUT = 5000;

const TYPE_ALIASES = {
    success: 'success',
    ok: 'success',
    error: 'error',
    danger: 'error',
    failed: 'error',
    warning: 'warning',
    warn: 'warning',
    info: 'info',
    notice: 'info',
};

let sequence = 0;

function normalise(payload) {
    const input = typeof payload === 'string' ? { message: payload } : payload || {};
    const type = TYPE_ALIASES[String(input.type || 'info').toLowerCase()] || 'info';

    return {
        id: ++sequence,
        type,
        title: input.title || null,
        message: String(input.message ?? ''),
        timeout: input.timeout === null || input.timeout === 0 ? 0 : Number(input.timeout || DEFAULT_TIMEOUT),
        show: false,
    };
}

export function registerToasts(Alpine) {
    Alpine.store('toasts', {
        items: [],

        /** Queue one toast. Returns its id. */
        push(payload) {
            const toast = normalise(payload);

            this.items.push(toast);

            // Next tick, so the enter transition actually runs.
            requestAnimationFrame(() => {
                const queued = this.items.find((item) => item.id === toast.id);

                if (queued) {
                    queued.show = true;
                }
            });

            if (toast.timeout > 0) {
                setTimeout(() => this.dismiss(toast.id), toast.timeout);
            }

            return toast.id;
        },

        /** Push a batch (used by the blade component for session flashes). */
        hydrate(payloads) {
            (Array.isArray(payloads) ? payloads : [payloads]).forEach((payload) => {
                if (payload) {
                    this.push(payload);
                }
            });
        },

        dismiss(id) {
            const toast = this.items.find((item) => item.id === id);

            if (!toast) {
                return;
            }

            toast.show = false;

            // Let the leave transition finish before removing the node.
            setTimeout(() => {
                this.items = this.items.filter((item) => item.id !== id);
            }, 200);
        },

        clear() {
            this.items = [];
        },

        success(message, title = null) {
            return this.push({ type: 'success', message, title });
        },

        error(message, title = null) {
            return this.push({ type: 'error', message, title });
        },

        warning(message, title = null) {
            return this.push({ type: 'warning', message, title });
        },

        info(message, title = null) {
            return this.push({ type: 'info', message, title });
        },
    });

    // Public handle for any script on the page: window.toasts.success('Saved').
    window.toasts = Alpine.store('toasts');

    // Allow plain DOM code to fire a toast without touching Alpine:
    // window.dispatchEvent(new CustomEvent('toast', { detail: { type, message } }))
    window.addEventListener('toast', (event) => {
        if (event.detail) {
            Alpine.store('toasts').push(event.detail);
        }
    });
}

export default { registerToasts };
