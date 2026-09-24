@push('scripts')
    {{--
        `adminDashboard` — the dashboard's only JavaScript.

        It is registered on `alpine:init` from an ordinary (non-module) inline script, which runs
        while the page parses, before the deferred Vite module calls `Alpine.start()`. That ordering
        is what lets a page-local component use `Alpine.data()` without touching resources/js/app.js.

        Three jobs, and nothing else:

          1. **Load a card's body.** `GET <widget url>` returns `{ data, html }`; the html goes into
             `[data-widget-body]` while the server-rendered `[data-widget-skeleton]` covers the gap.
             No markup is built here — the skeleton and the body are both Blade.
          2. **Arrange.** Drag within a section, or the arrow buttons, or the eye. Order is always
             read back out of the DOM, so the array and what the user sees cannot diverge.
          3. **Save.** One PUT with `{ order, hidden }`. The server re-checks every key against this
             viewer's permissions, so this payload is a request, not an authority.
    --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('adminDashboard', (config = {}) => ({
                // ── configuration ───────────────────────────────────────────────────────────
                widgetUrl: config.widgetUrl || null,
                layoutUrl: config.layoutUrl || null,

                // ── state ───────────────────────────────────────────────────────────────────
                order: Array.isArray(config.order) ? [...config.order] : [],
                hidden: Array.isArray(config.hidden) ? [...config.hidden] : [],
                defaultOrder: Array.isArray(config.defaultOrder) ? [...config.defaultOrder] : [],
                saved: { order: [], hidden: [] },
                customising: false,
                dirty: false,
                saving: false,
                loading: [],
                draggedKey: null,

                /**
                 * The grid element.
                 *
                 * Captured in init() rather than read as `$el` on demand: inside an x-on handler
                 * Alpine sets `$el` to the element carrying the directive — the button that was
                 * clicked — so a lookup rooted at `$el` would search inside the button and quietly
                 * find nothing.
                 */
                root: null,

                init() {
                    this.root = this.$el;

                    // The DOM is the truth for order: the server already arranged it.
                    this.order = this.keysInDom();
                    this.saved = { order: [...this.order], hidden: [...this.hidden] };

                    // Anything the server left pending (expensive, or switched off and now shown).
                    this.$nextTick(() => this.loadPending());

                    window.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape' && this.customising && !this.dirty) {
                            this.customising = false;
                        }
                    });

                    window.addEventListener('beforeunload', (event) => {
                        if (!this.dirty) {
                            return;
                        }

                        event.preventDefault();
                        event.returnValue = '';
                    });
                },

                // ── reading the grid ────────────────────────────────────────────────────────

                cards() {
                    return Array.from((this.root ?? document).querySelectorAll('[data-widget-key]'));
                },

                card(key) {
                    return (this.root ?? document).querySelector(`[data-widget-key="${key}"]`);
                },

                /**
                 * Which widget is this element inside?
                 *
                 * Every card-level directive uses this instead of a printed key, because Blade
                 * does not compile an @@js() directive inside an x-ui.* component tag's
                 * attributes — the key would arrive as literal text. Reading it from the DOM is
                 * also the one lookup that stays correct after a card is dragged elsewhere.
                 */
                keyOf(element) {
                    return element?.closest?.('[data-widget-key]')?.dataset.widgetKey ?? null;
                },

                keysInDom() {
                    return this.cards().map((card) => card.dataset.widgetKey);
                },

                isHidden(key) {
                    return this.hidden.includes(key);
                },

                isLoading(key) {
                    return this.loading.includes(key);
                },

                get busy() {
                    return this.loading.length > 0;
                },

                visibleCount() {
                    return this.order.filter((key) => !this.isHidden(key)).length;
                },

                sectionHasVisible(keys) {
                    return (keys || []).some((key) => !this.isHidden(key));
                },

                /** Classes Alpine owns on a card; everything else on the element is untouched. */
                cardClasses(key) {
                    const hidden = this.isHidden(key);

                    return {
                        // A switched-off card disappears outside customise mode and greys out inside it.
                        hidden: hidden && !this.customising,
                        'opacity-50': hidden && this.customising,
                        'cursor-grab': this.customising,
                        'ring-2 ring-brand-500 ring-offset-2 dark:ring-offset-slate-950 rounded-xl':
                            this.draggedKey === key,
                    };
                },

                // ── loading a body ──────────────────────────────────────────────────────────

                loadPending() {
                    this.cards()
                        .filter((card) => card.dataset.widgetState === 'loading' && !this.isHidden(card.dataset.widgetKey))
                        .forEach((card) => this.load(card.dataset.widgetKey));
                },

                state(card, next) {
                    if (!card) {
                        return;
                    }

                    card.dataset.widgetState = next;

                    const show = (selector, visible) => {
                        const node = card.querySelector(selector);

                        node?.classList.toggle('hidden', !visible);
                    };

                    show('[data-widget-skeleton]', next === 'loading');
                    show('[data-widget-body]', next === 'ready');
                    show('[data-widget-error]', next === 'error');
                },

                async load(key, force = false) {
                    const card = this.card(key);
                    const url = card?.dataset.widgetUrl;

                    if (!card || !url || this.isLoading(key)) {
                        return;
                    }

                    if (!force && card.dataset.widgetState === 'ready') {
                        return;
                    }

                    this.loading.push(key);
                    this.state(card, 'loading');

                    try {
                        const response = await fetch(url, {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });

                        if (!response.ok) {
                            throw new Error(
                                response.status === 429
                                    ? 'Too many refreshes — wait a moment and try again.'
                                    : `The server answered ${response.status}.`,
                            );
                        }

                        const payload = await response.json();
                        const body = card.querySelector('[data-widget-body]');

                        if (body && typeof payload.html === 'string') {
                            // charts.js watches the document, so a chart inside this fragment draws
                            // itself as soon as it lands.
                            body.innerHTML = payload.html;
                        }

                        this.state(card, 'ready');
                    } catch (error) {
                        const message = card.querySelector('[data-widget-error-message]');

                        if (message) {
                            message.textContent = error.message || 'This card could not be loaded.';
                        }

                        this.state(card, 'error');
                    } finally {
                        this.loading = this.loading.filter((item) => item !== key);
                    }
                },

                refreshAll() {
                    this.order
                        .filter((key) => !this.isHidden(key))
                        .forEach((key) => this.load(key, true));
                },

                // ── arranging ───────────────────────────────────────────────────────────────

                toggleCustomising() {
                    this.customising = !this.customising;
                },

                toggleHidden(key) {
                    if (this.isHidden(key)) {
                        this.hidden = this.hidden.filter((item) => item !== key);

                        // It was never measured while switched off, so fetch it now.
                        this.$nextTick(() => this.load(key));
                    } else {
                        this.hidden = [...this.hidden, key];
                    }

                    this.touch();
                },

                /** Move a card one place within its own section. */
                move(key, delta) {
                    const card = this.card(key);

                    if (!card) {
                        return;
                    }

                    const siblings = Array.from(card.parentElement.children).filter(
                        (node) => node.dataset?.widgetKey,
                    );

                    const index = siblings.indexOf(card);
                    const target = index + delta;

                    if (target < 0 || target >= siblings.length) {
                        return;
                    }

                    if (delta < 0) {
                        card.parentElement.insertBefore(card, siblings[target]);
                    } else {
                        card.parentElement.insertBefore(card, siblings[target].nextSibling);
                    }

                    card.querySelector('button, a')?.focus({ preventScroll: true });
                    this.syncOrder();
                },

                onDragStart(event, key) {
                    if (!this.customising) {
                        return;
                    }

                    this.draggedKey = key;
                    event.dataTransfer.effectAllowed = 'move';

                    // Firefox will not start a drag without payload.
                    event.dataTransfer.setData('text/plain', key);
                },

                onDragOver(event, key) {
                    if (!this.customising || !this.draggedKey || this.draggedKey === key) {
                        return;
                    }

                    const dragged = this.card(this.draggedKey);
                    const target = this.card(key);

                    // A card dragged into another section would snap back on reload, because the
                    // sections are the registry's grouping and not the user's. So refuse it.
                    if (!dragged || !target || dragged.parentElement !== target.parentElement) {
                        return;
                    }

                    const box = target.getBoundingClientRect();
                    const after = event.clientX > box.left + box.width / 2;

                    target.parentElement.insertBefore(dragged, after ? target.nextSibling : target);
                },

                onDragEnd() {
                    if (!this.draggedKey) {
                        return;
                    }

                    this.draggedKey = null;
                    this.syncOrder();
                },

                syncOrder() {
                    const next = this.keysInDom();

                    if (next.join('|') !== this.order.join('|')) {
                        this.order = next;
                        this.touch();
                    }
                },

                touch() {
                    this.dirty =
                        this.order.join('|') !== this.saved.order.join('|') ||
                        [...this.hidden].sort().join('|') !== [...this.saved.hidden].sort().join('|');
                },

                // ── saving ──────────────────────────────────────────────────────────────────

                async save() {
                    if (!this.layoutUrl || this.saving) {
                        return;
                    }

                    this.saving = true;

                    try {
                        const response = await fetch(this.layoutUrl, {
                            method: 'PUT',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN':
                                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            },
                            body: JSON.stringify({ order: this.order, hidden: this.hidden }),
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            throw new Error(
                                payload.message ||
                                    Object.values(payload.errors || {}).flat()[0] ||
                                    'The layout could not be saved.',
                            );
                        }

                        this.saved = { order: [...this.order], hidden: [...this.hidden] };
                        this.dirty = false;
                        this.customising = false;

                        window.toasts?.success?.(payload.message || 'Dashboard layout saved.');
                    } catch (error) {
                        window.toasts?.error?.(error.message || 'The layout could not be saved.');
                    } finally {
                        this.saving = false;
                    }
                },

                /** Put the cards back where they were before this customise session. */
                discard() {
                    this.applyOrder(this.saved.order);
                    this.hidden = [...this.saved.hidden];
                    this.dirty = false;
                    this.customising = false;
                    this.$nextTick(() => this.loadPending());
                },

                /**
                 * Forget the arrangement entirely: back to the order the widgets themselves
                 * declare (group, then sort, then title), with nothing hidden.
                 */
                resetLayout() {
                    this.hidden = [];
                    this.applyOrder(this.defaultOrder.length ? this.defaultOrder : this.keysInDom());
                    this.touch();
                    this.dirty = true;
                    this.$nextTick(() => this.loadPending());
                },

                /** Re-order the DOM to match a list of keys, section by section. */
                applyOrder(keys) {
                    (keys || []).forEach((key) => {
                        const card = this.card(key);

                        card?.parentElement.appendChild(card);
                    });

                    this.order = this.keysInDom();
                },
            }));
        });
    </script>
@endpush

@push('styles')
    {{-- Customising is Alpine-driven, so hide its controls when there is no Alpine. --}}
    <noscript>
        <style>
            [data-requires-js] { display: none !important; }
        </style>
    </noscript>
@endpush

@if (collect($sections)->pluck('widgets')->flatten(1)->contains(fn (array $card): bool => $card['deferred']))
    @once('ui-chart-runtime')
        {{--
            A card whose body arrives later may contain a chart, and `<x-ui.chart>` can only pull
            the runtime in when it renders on the server. The shared `@@once` id means this is
            skipped whenever a chart already rendered inline, so the file is never requested twice.
        --}}
        @push('scripts')
            @vite('resources/js/charts.js')
        @endpush
    @endonce
@endif
