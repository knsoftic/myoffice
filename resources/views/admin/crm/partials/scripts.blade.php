{{--
    The CRM screens' JavaScript (phase-05 §8.2, §8.4, §8.6). Include it from any CRM view:

        @include('admin.crm.partials.scripts')

    It pushes once per request, so including it from several views or partials is harmless. Registered on
    `alpine:init` from a classic inline script, which runs while the page parses and before the deferred Vite module
    calls Alpine.start() — the same pattern as admin/cms/partials/scripts — so no edit to resources/js is needed and
    no JS dependency is added (§8.2 "no new JS dependency").

    Components:
      crmBoard            the Kanban board: native HTML5 drag-and-drop plus a "Move to" menu posting the identical
                          endpoint, optimistic move with a saving state, local header adjustment in integer paisa
                          (BigInt — never a float), authoritative figures from every response, spring-back on
                          409 / 422, the reason / follow-up modal before a move that cannot succeed without it, an
                          aria-live announcement of every move, lazy "Load more" per column.
      crmDuplicateCheck   the live duplicate check on the lead form: debounced 500 ms after blur of phone, WhatsApp or
                          email; renders the server's matches; submit needs `confirm_duplicate` ticked, and stays
                          disabled on an exact match while crm.duplicate_block_on_exact is on.
      crmImportProgress   polls admin.leads.import.show (JSON) every two seconds while an import runs.

    Nothing here decides what is allowed: every request is re-authorised by the route's `can:` middleware, the policy
    and the service (§2.11 transitions are validated on the server; the local check only saves a round trip).
--}}

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                const Alpine = window.Alpine;

                if (! Alpine) {
                    return;
                }

                const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                const toast = (type, message) => {
                    const store = Alpine.store('toasts');

                    if (store && typeof store.push === 'function' && message) {
                        store.push({ type, message });
                    }
                };

                const jsonHeaders = () => ({
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                });

                const messageFrom = async (response, fallback) => {
                    try {
                        const body = await response.json();

                        if (body && body.errors) {
                            const first = Object.values(body.errors).flat()[0];

                            if (first) {
                                return String(first);
                            }
                        }

                        if (body && body.message) {
                            return String(body.message);
                        }
                    } catch (error) {
                        // Not JSON.
                    }

                    if (response.status === 403) {
                        return 'You do not have permission to do that.';
                    }

                    return response.status === 419 ? 'Your session expired. Reload the page and try again.' : fallback;
                };

                /* ------------------------------------------------------------------------------
                 | Money in integer minor units. A decimal(15,2) string becomes a BigInt of paisa,
                 | is added or subtracted, and becomes a string again: no float ever holds money.
                 ------------------------------------------------------------------------------ */
                const toMinor = (value) => {
                    const text = String(value ?? '').trim();

                    if (! /^\d+(\.\d{1,2})?$/.test(text)) {
                        return 0n;
                    }

                    const [integer, fraction = ''] = text.split('.');

                    return BigInt(integer) * 100n + BigInt((fraction + '00').slice(0, 2));
                };

                const fromMinor = (minor) => {
                    const safe = minor < 0n ? 0n : minor;
                    const digits = safe.toString().padStart(3, '0');

                    return `${digits.slice(0, -2)}.${digits.slice(-2)}`;
                };

                /**
                 * A formatter shaped like the server's money(): the page prints money('1234567.89') and this reads
                 * the symbol, its position, the grouping and the decimal separator back out of that sample. The
                 * result is only ever provisional — every response overwrites it with the server's own string.
                 */
                const moneyFormatter = (sample) => {
                    const text = String(sample || '');
                    const first = text.search(/\d/);
                    let last = -1;

                    for (let index = text.length - 1; index >= 0; index--) {
                        if (/\d/.test(text[index])) {
                            last = index;
                            break;
                        }
                    }

                    if (first < 0) {
                        return (value) => String(value ?? '');
                    }

                    const prefix = text.slice(0, first);
                    const suffix = text.slice(last + 1);
                    const core = text.slice(first, last + 1);
                    const decimalMatch = core.match(/(\D)(\d{2})$/);
                    const integerPart = decimalMatch ? core.slice(0, -3) : core;
                    const group = (integerPart.match(/^1(\D*)234/) || [])[1] ?? '';

                    return (value) => {
                        const minor = toMinor(value);
                        const whole = decimalMatch ? (minor / 100n).toString() : ((minor + 50n) / 100n).toString();
                        const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, group);
                        const cents = (minor % 100n).toString().padStart(2, '0');

                        return prefix + grouped + (decimalMatch ? decimalMatch[1] + cents : '') + suffix;
                    };
                };

                const countFormatter = (sample) => {
                    const group = (String(sample || '').match(/1(\D*)234/) || [])[1] ?? ',';

                    return (value) => String(Math.max(0, Number(value) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, group);
                };

                /* ------------------------------------------------------------------------------
                 | crmBoard — the Kanban board (§8.2)
                 ------------------------------------------------------------------------------ */
                Alpine.data('crmBoard', (config = {}) => ({
                    moveUrl: config.moveUrl || null,
                    columnUrls: config.columnUrls || {},
                    transitions: config.transitions || {},
                    labels: config.labels || {},
                    followUpRequired: Array.isArray(config.followUpRequired) ? config.followUpRequired : [],
                    columns: config.columns || {},
                    canMove: Boolean(config.canMove),
                    followUpDefaultAt: config.followUpDefaultAt || '',
                    formatMoney: moneyFormatter(config.moneySample),
                    formatCount: countFormatter(config.numberSample),
                    announcement: '',
                    dragging: null,
                    over: null,
                    pending: null,
                    form: { lost_choice: '', lost_other: '', reason: '', follow_up_type: 'call', follow_up_at: '', follow_up_notes: '' },
                    won: null,
                    board: null,

                    // The board's own element. `$root` is NOT used by the methods below: called from a nested
                    // component (a card's "Move to" menu, the details modal) it would resolve to that component.
                    init() {
                        this.board = this.$el;
                    },

                    label(status) {
                        return this.labels[status] || status;
                    },

                    allowed(from) {
                        return Array.isArray(this.transitions[from]) ? this.transitions[from] : [];
                    },

                    list(status) {
                        return (this.board || this.$root).querySelector(`[data-column-list="${status}"]`);
                    },

                    cardData(element) {
                        return {
                            el: element,
                            id: element.dataset.leadId,
                            from: element.dataset.status,
                            name: element.dataset.name || 'The lead',
                            budget: element.dataset.budget || '',
                            hasFollowUp: element.dataset.openFollowUp === '1',
                        };
                    },

                    columnClass(status) {
                        if (! this.dragging) {
                            return '';
                        }

                        if (this.dragging.from === status) {
                            return 'ring-1 ring-inset ring-slate-300 dark:ring-slate-600';
                        }

                        if (! this.allowed(this.dragging.from).includes(status)) {
                            return 'opacity-50';
                        }

                        return this.over === status
                            ? 'ring-2 ring-inset ring-brand-500 bg-brand-50/60 dark:bg-brand-500/10'
                            : 'ring-1 ring-inset ring-brand-300 dark:ring-brand-500/40';
                    },

                    dragStart(event) {
                        const element = event.target.closest('[data-lead-id]');

                        if (! element || ! this.canMove || element.getAttribute('aria-busy') === 'true') {
                            event.preventDefault();

                            return;
                        }

                        this.dragging = this.cardData(element);
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', String(this.dragging.id));
                        element.classList.add('opacity-50');
                    },

                    dragEnd(event) {
                        event.target.closest('[data-lead-id]')?.classList.remove('opacity-50');
                        this.over = null;
                        setTimeout(() => {
                            this.dragging = null;
                        }, 0);
                    },

                    dragOver(status, event) {
                        if (! this.dragging) {
                            return;
                        }

                        this.over = status;
                        event.dataTransfer.dropEffect = this.allowed(this.dragging.from).includes(status) ? 'move' : 'none';
                    },

                    dragLeave(status, event) {
                        if (this.over === status && ! event.currentTarget.contains(event.relatedTarget)) {
                            this.over = null;
                        }
                    },

                    drop(status) {
                        const card = this.dragging;

                        this.dragging = null;
                        this.over = null;

                        if (card) {
                            card.el.classList.remove('opacity-50');
                            this.request(card, status);
                        }
                    },

                    /** The keyboard / touch path: the card's "Move to" menu. */
                    moveVia(control, status) {
                        const element = control.closest('[data-lead-id]');

                        if (element) {
                            this.request(this.cardData(element), status);
                        }
                    },

                    say(message, type = 'info') {
                        this.announcement = message;
                        toast(type, message);
                    },

                    request(card, to) {
                        if (! this.canMove || ! card || card.from === to) {
                            return;
                        }

                        if (! this.allowed(card.from).includes(to)) {
                            const allowed = this.allowed(card.from).map((status) => this.label(status)).join(', ') || 'none';
                            this.say(`${card.name} cannot move from ${this.label(card.from)} to ${this.label(to)}. Allowed: ${allowed}.`, 'error');

                            return;
                        }

                        const needs = {
                            lost: to === 'lost',
                            reopen: ['won', 'lost'].includes(card.from),
                            followUp: this.followUpRequired.includes(to) && ! card.hasFollowUp,
                        };

                        // A move that cannot succeed without details asks for them BEFORE the optimistic move.
                        if (needs.lost || needs.reopen || needs.followUp) {
                            this.ask(card, to, needs);

                            return;
                        }

                        this.commit(card, to, {});
                    },

                    ask(card, to, needs) {
                        this.form = { lost_choice: '', lost_other: '', reason: '', follow_up_type: 'call', follow_up_at: this.followUpDefaultAt, follow_up_notes: '' };
                        this.pending = { card, to, needs };
                        this.$dispatch('open-modal', 'lead-board-move');
                    },

                    confirmPending() {
                        const pending = this.pending;

                        if (! pending) {
                            return;
                        }

                        const payload = {};

                        if (pending.needs.lost) {
                            const reason = this.form.lost_choice === '__other' ? this.form.lost_other.trim() : this.form.lost_choice;

                            if (! reason) {
                                toast('error', 'Choose or type the reason this lead was lost.');

                                return;
                            }

                            payload.lost_reason = reason;
                        }

                        if (pending.needs.reopen) {
                            if (! this.form.reason.trim()) {
                                toast('error', 'Say why this lead is being reopened.');

                                return;
                            }

                            payload.reason = this.form.reason.trim();
                        }

                        if (pending.needs.followUp) {
                            if (! this.form.follow_up_at) {
                                toast('error', 'Pick when the follow-up is due.');

                                return;
                            }

                            payload.follow_up = {
                                type: this.form.follow_up_type,
                                scheduled_at: this.form.follow_up_at,
                                notes: this.form.follow_up_notes,
                            };
                        }

                        this.pending = null;
                        this.$dispatch('close-modal', 'lead-board-move');
                        this.commit(pending.card, pending.to, payload);
                    },

                    cancelPending() {
                        if (this.pending) {
                            this.announcement = `${this.pending.card.name} was not moved.`;
                        }

                        this.pending = null;
                        this.$dispatch('close-modal', 'lead-board-move');
                    },

                    adjust(status, delta, budget) {
                        const column = this.columns[status];

                        if (! column) {
                            return;
                        }

                        column.count = Math.max(0, (Number(column.count) || 0) + delta);

                        if (budget !== '') {
                            column.with_budget = Math.max(0, (Number(column.with_budget) || 0) + delta);
                            column.value_sum = fromMinor(toMinor(column.value_sum) + BigInt(delta) * toMinor(budget));
                            column.value_sum_formatted = this.formatMoney(column.value_sum);
                        }
                    },

                    applyColumns(figures) {
                        if (! figures || typeof figures !== 'object') {
                            return;
                        }

                        Object.entries(figures).forEach(([status, values]) => {
                            if (this.columns[status] && values && typeof values === 'object') {
                                ['count', 'value_sum', 'value_sum_formatted', 'with_budget'].forEach((key) => {
                                    if (values[key] !== undefined && values[key] !== null) {
                                        this.columns[status][key] = values[key];
                                    }
                                });
                            }
                        });
                    },

                    async commit(card, to, payload) {
                        const element = card.el;
                        const from = card.from;
                        const fromList = this.list(from);
                        const toList = this.list(to);

                        if (! element || ! toList || ! this.moveUrl) {
                            return;
                        }

                        const anchor = element.nextElementSibling;
                        const snapshot = JSON.parse(JSON.stringify({ from: this.columns[from] || null, to: this.columns[to] || null }));

                        // Optimistic: move the card, mark it saving, adjust both headers by its count and budget.
                        toList.prepend(element);
                        element.dataset.status = to;
                        element.setAttribute('aria-busy', 'true');
                        element.classList.add('animate-pulse', 'pointer-events-none');
                        this.adjust(from, -1, card.budget);
                        this.adjust(to, 1, card.budget);
                        this.announcement = `Moving ${card.name} to ${this.label(to)}…`;

                        let response = null;
                        let body = {};

                        try {
                            response = await fetch(this.moveUrl.replace('987654321', String(card.id)), {
                                method: 'PATCH',
                                headers: jsonHeaders(),
                                credentials: 'same-origin',
                                body: JSON.stringify({ to_status: to, expected_from_status: from, ...payload }),
                            });
                            body = await response.json().catch(() => ({}));
                        } catch (error) {
                            response = null;
                        }

                        element.removeAttribute('aria-busy');
                        element.classList.remove('animate-pulse', 'pointer-events-none');

                        if (response && response.ok) {
                            // The server's figures win, so a colleague's concurrent change self-corrects.
                            this.applyColumns(body.columns);

                            if (typeof body.card_html === 'string' && body.card_html.trim() !== '') {
                                const holder = document.createElement('template');
                                holder.innerHTML = body.card_html.trim();
                                const fresh = holder.content.firstElementChild;

                                if (fresh) {
                                    element.replaceWith(fresh);
                                }
                            }

                            const message = body.message || `${card.name} moved to ${this.label(to)}.`;
                            this.announcement = message;
                            toast('success', message);
                            this.won = to === 'won' && body.convert_url ? { name: card.name, url: body.convert_url } : null;

                            return;
                        }

                        // Spring back to the original column and position, then take the error payload's figures.
                        if (fromList) {
                            if (anchor && anchor.parentElement === fromList) {
                                fromList.insertBefore(element, anchor);
                            } else {
                                fromList.appendChild(element);
                            }
                        }

                        element.dataset.status = from;

                        if (snapshot.from) {
                            this.columns[from] = snapshot.from;
                        }

                        if (snapshot.to) {
                            this.columns[to] = snapshot.to;
                        }

                        this.applyColumns(body.columns);

                        let message = body.message || 'The lead was not moved.';
                        const errors = body && body.errors ? body.errors : null;

                        if (! response) {
                            message = 'The connection dropped. The lead was not moved.';
                        } else if (response.status === 409) {
                            message = body.message || `${card.name} was changed by someone else and is now ${this.label(body.current_status)}. Refresh the board to see it.`;
                        } else if (response.status === 422) {
                            const firstError = errors ? Object.values(errors).flat()[0] : null;
                            const allowed = Array.isArray(body.allowed)
                                ? ` Allowed: ${body.allowed.map((status) => this.label(status)).join(', ') || 'none'}.`
                                : '';
                            message = `${firstError || body.message || 'That move is not allowed.'}${allowed}`;
                        } else if (response.status === 403) {
                            message = 'You do not have permission to move this lead.';
                        } else if (response.status === 419) {
                            message = 'Your session expired. Reload the page and try again.';
                        }

                        this.announcement = `${card.name} was not moved. ${message}`;
                        toast('error', message);

                        // The server asked for details the board did not know it needed: ask, then retry.
                        if (response && response.status === 422 && errors) {
                            const keys = Object.keys(errors);
                            const wantsFollowUp = keys.some((key) => key.startsWith('follow_up'));
                            const wantsLost = keys.includes('lost_reason');
                            const wantsReason = keys.includes('reason');

                            if (wantsFollowUp || wantsLost || wantsReason) {
                                this.ask({ ...card, from, hasFollowUp: card.hasFollowUp && ! wantsFollowUp }, to, {
                                    lost: wantsLost || to === 'lost',
                                    reopen: wantsReason || ['won', 'lost'].includes(from),
                                    followUp: wantsFollowUp,
                                });
                            }
                        }
                    },

                    async loadMore(status) {
                        const column = this.columns[status];
                        const url = this.columnUrls[status];

                        if (! column || ! url || column.loading || ! column.has_more) {
                            return;
                        }

                        column.loading = true;

                        try {
                            const target = new URL(url, window.location.origin);
                            target.searchParams.set('page', String(column.next_page || 2));

                            const response = await fetch(target.toString(), {
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                            });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'More leads could not be loaded.'));
                            }

                            const body = await response.json();
                            const list = this.list(status);

                            if (list && typeof body.html === 'string') {
                                const holder = document.createElement('template');
                                holder.innerHTML = body.html;

                                // A card moved here earlier may come back on the next page: never show it twice.
                                Array.from(holder.content.children).forEach((card) => {
                                    const id = card.getAttribute('data-lead-id');

                                    if (! id || ! (this.board || this.$root).querySelector(`[data-lead-id="${id}"]`)) {
                                        list.appendChild(card);
                                    }
                                });
                            }

                            column.has_more = Boolean(body.has_more);
                            column.next_page = body.next_page ?? null;

                            if (body.column) {
                                this.applyColumns({ [status]: body.column });
                            }

                            this.announcement = `More ${this.label(status)} leads loaded.`;
                        } catch (error) {
                            toast('error', error.message || 'More leads could not be loaded.');
                        } finally {
                            column.loading = false;
                        }
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | crmDuplicateCheck — the live duplicate panel on the lead form (§8.4)
                 ------------------------------------------------------------------------------ */
                Alpine.data('crmDuplicateCheck', (config = {}) => ({
                    url: config.url || null,
                    linkUrl: config.linkUrl || null,
                    ignoreLeadId: config.ignoreLeadId || null,
                    blockOnExact: Boolean(config.blockOnExact),
                    confirmed: Boolean(config.confirmed),
                    matches: [],
                    hasExact: false,
                    loading: false,
                    timer: null,
                    last: '',
                    linking: null,
                    linkNote: '',
                    linkedTo: config.linkedTo || null,
                    host: null,

                    // The form element, captured once: `$root` would be a nested component's root when a method runs
                    // from inside one (the assignment card has its own x-data).
                    init() {
                        this.host = this.$el;
                    },

                    queue() {
                        clearTimeout(this.timer);
                        this.timer = setTimeout(() => this.check(), 500);
                    },

                    field(name) {
                        const input = (this.host || this.$root).querySelector(`[name="${name}"]`);

                        return input ? String(input.value || '').trim() : '';
                    },

                    async check() {
                        if (! this.url) {
                            return;
                        }

                        const payload = {
                            phone: this.field('phone'),
                            whatsapp: this.field('whatsapp'),
                            email: this.field('email'),
                            country_code: this.field('country_code'),
                            ignore_lead_id: this.ignoreLeadId,
                        };

                        const key = JSON.stringify(payload);

                        if (key === this.last) {
                            return;
                        }

                        this.last = key;

                        if (! payload.phone && ! payload.whatsapp && ! payload.email) {
                            this.matches = [];
                            this.hasExact = false;

                            return;
                        }

                        this.loading = true;

                        try {
                            const response = await fetch(this.url, {
                                method: 'POST',
                                headers: jsonHeaders(),
                                credentials: 'same-origin',
                                body: JSON.stringify(payload),
                            });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'The duplicate check did not run.'));
                            }

                            const body = await response.json();
                            this.matches = Array.isArray(body.matches) ? body.matches : [];
                            this.hasExact = typeof body.has_exact === 'boolean'
                                ? body.has_exact
                                : this.matches.some((match) => Boolean(match.is_exact));

                            if (typeof body.block_on_exact === 'boolean') {
                                this.blockOnExact = body.block_on_exact;
                            }

                            if (this.matches.length === 0) {
                                this.confirmed = false;
                            }
                        } catch (error) {
                            // Not fatal: the server runs the same check again when the form is saved.
                            this.last = '';
                        } finally {
                            this.loading = false;
                        }
                    },

                    get blocked() {
                        return this.blockOnExact && this.hasExact;
                    },

                    get submitDisabled() {
                        return this.blocked || (this.matches.length > 0 && ! this.confirmed);
                    },

                    matchLabel(match) {
                        return match.match_type_label || match.match_label || String(match.match_type || 'contact').replace(/_/g, ' ');
                    },

                    recordLabel(match) {
                        return match.record_type_label || String(match.record_type || 'record').replace(/_/g, ' ');
                    },

                    startLink(match) {
                        this.linking = match.lead_id || null;
                        this.linkNote = '';
                    },

                    confirmLink(match) {
                        const note = this.linkNote.trim();

                        if (! note) {
                            toast('error', 'Add a short note saying why these are the same person.');

                            return;
                        }

                        if (this.linkUrl) {
                            // An existing lead: link it now through admin.leads.duplicate-link.
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = this.linkUrl;
                            [['_token', csrf()], ['original_lead_id', match.lead_id], ['note', note]].forEach(([name, value]) => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = name;
                                input.value = value;
                                form.appendChild(input);
                            });
                            document.body.appendChild(form);
                            form.submit();

                            return;
                        }

                        // A new lead: carried with the form, linked once the lead exists.
                        this.linkedTo = { lead_id: match.lead_id, name: match.name || null, note };
                        this.confirmed = true;
                        this.linking = null;
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | crmImportProgress — the running import's counters (§8.6 step 4)
                 ------------------------------------------------------------------------------ */
                Alpine.data('crmImportProgress', (config = {}) => ({
                    url: config.url || null,
                    state: config.state || {},
                    finished: Boolean(config.finished),
                    timer: null,
                    failures: 0,

                    init() {
                        if (! this.finished && this.url) {
                            this.timer = setTimeout(() => this.poll(), 2000);
                        }
                    },

                    destroy() {
                        clearTimeout(this.timer);
                    },

                    async poll() {
                        try {
                            const response = await fetch(this.url, {
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                            });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'The progress could not be read.'));
                            }

                            const body = await response.json();
                            this.state = { ...this.state, ...body };
                            this.failures = 0;

                            if (body.finished) {
                                this.finished = true;
                                toast(body.status === 'failed' ? 'error' : 'success', body.message || 'The import has finished.');
                                setTimeout(() => window.location.reload(), 1200);

                                return;
                            }
                        } catch (error) {
                            this.failures++;

                            if (this.failures >= 5) {
                                toast('error', 'Progress updates stopped. Reload the page to see the latest counts.');

                                return;
                            }
                        }

                        this.timer = setTimeout(() => this.poll(), 2000);
                    },
                }));
            });
        </script>
    @endpush
@endonce
