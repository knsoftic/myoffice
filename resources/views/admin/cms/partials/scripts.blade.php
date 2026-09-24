{{--
    The admin CMS screens' only JavaScript (phase-03 §8.1, §8.2). Include it from any CMS view:

        @include('admin.cms.partials.scripts')

    It pushes once per request, so including it from several partials is harmless.

    Registered on `alpine:init` from a classic inline script: that runs while the page parses, before
    the deferred Vite module calls `Alpine.start()`, so page-local components need no edit to
    resources/js/app.js (the same pattern as admin/dashboard/partials/scripts and roles/partials/form).

    Components:
      cmsSortable     the one drag-to-reorder implementation (sections, repeater items, FAQs, FAQ
                      categories). Native HTML5 drag from a handle, plus Move up / Move down buttons,
                      an aria-live announcement, and on failure the previous order is restored and an
                      error toast raised. It always posts the FULL ordered id list (INV-5).
      cmsMenuTree     the two-level variant for the menu builder: posts `tree` = [{id, children:[{id}]}]
                      and refuses a third level in the browser before the server (and the CHECK) does.
      cmsMediaPicker  chooses one or many media_assets ids from the library JSON on the page.
      cmsUploader     multi-file upload with per-file progress and the server's own refusal reason.
      cmsSlug         a live /{slug} preview with an "auto from title" switch and a reserved-word hint.
      cmsLengthMeter  a character counter that turns amber near the limit and rose past it.
      cmsBulk         sequential POSTs for a bulk action over individual routes, then a reload.
      cmsDirty        warns before leaving a form with unsaved changes (the publish bar's pattern).
      cmsFetchList    lazy JSON lists (usage popovers, link checks).

    No component here ever decides what is allowed: every request it makes is re-authorised by the
    route's `can:` middleware and the policy. A refusal comes back as JSON and becomes a toast.
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

                    if (store && typeof store.push === 'function') {
                        store.push({ type, message });
                    }
                };

                /** Pull a readable message out of a Laravel JSON error response. */
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
                        // Not JSON: fall through to the generic message.
                    }

                    return response.status === 403
                        ? 'You do not have permission to do that.'
                        : (response.status === 419 ? 'Your session expired. Reload the page and try again.' : fallback);
                };

                const postJson = (url, payload, method = 'POST') => fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });

                /* ------------------------------------------------------------------------------
                 | cmsSortable
                 | Markup:
                 |   <div x-data="cmsSortable({ url, payload: {...}, key: 'order', noun: 'section' })">
                 |     <p class="sr-only" aria-live="polite" x-text="announcement"></p>
                 |     <ul data-sortable-list>
                 |       <li data-sortable-id="4" data-sortable-label="Hero"
                 |           x-on:dragstart="dragStart($event)" x-on:dragover.prevent="dragOver($event)"
                 |           x-on:drop.prevent="drop()" x-on:dragend="dragEnd($event)">
                 |         <button type="button" data-sortable-handle x-on:pointerdown="arm($event)" …>
                 |         <button type="button" x-on:click="move($el, -1)">Move up</button>
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsSortable', (config = {}) => ({
                    url: config.url || null,
                    payload: config.payload || {},
                    key: config.key || 'order',
                    noun: config.noun || 'item',
                    disabled: Boolean(config.disabled),
                    saving: false,
                    announcement: '',
                    dragged: null,
                    before: [],
                    list: null,

                    init() {
                        this.list = this.$el.querySelector('[data-sortable-list]') || this.$el;
                    },

                    rows() {
                        return Array.from(this.list.children).filter((row) => row.hasAttribute('data-sortable-id'));
                    },

                    ids() {
                        return this.rows().map((row) => row.getAttribute('data-sortable-id'));
                    },

                    arm(event) {
                        if (this.disabled || this.saving) {
                            return;
                        }

                        const row = event.target.closest('[data-sortable-id]');

                        if (row) {
                            row.setAttribute('draggable', 'true');
                        }
                    },

                    dragStart(event) {
                        const row = event.target.closest('[data-sortable-id]');

                        if (! row || row.getAttribute('draggable') !== 'true' || this.disabled) {
                            event.preventDefault();

                            return;
                        }

                        this.dragged = row;
                        this.before = this.ids();
                        row.classList.add('opacity-50', 'ring-2', 'ring-brand-500/40');
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', row.getAttribute('data-sortable-id'));
                    },

                    dragOver(event) {
                        if (! this.dragged) {
                            return;
                        }

                        const row = event.target.closest('[data-sortable-id]');

                        if (! row || row === this.dragged || row.parentElement !== this.list) {
                            return;
                        }

                        const box = row.getBoundingClientRect();
                        const after = event.clientY > box.top + box.height / 2;

                        this.list.insertBefore(this.dragged, after ? row.nextSibling : row);
                    },

                    drop() {
                        // The move already happened in dragOver; dragEnd saves.
                    },

                    dragEnd(event) {
                        const row = this.dragged || event.target.closest('[data-sortable-id]');

                        if (row) {
                            row.removeAttribute('draggable');
                            row.classList.remove('opacity-50', 'ring-2', 'ring-brand-500/40');
                        }

                        if (! this.dragged) {
                            return;
                        }

                        const moved = this.dragged;
                        this.dragged = null;

                        if (this.before.join(',') !== this.ids().join(',')) {
                            this.save(moved);
                        }
                    },

                    /** Keyboard / button path: the same payload as a drag. */
                    move(control, direction) {
                        if (this.disabled || this.saving) {
                            return;
                        }

                        const row = control.closest('[data-sortable-id]');
                        const rows = this.rows();
                        const index = rows.indexOf(row);
                        const target = index + direction;

                        if (index < 0 || target < 0 || target >= rows.length) {
                            return;
                        }

                        this.before = this.ids();
                        this.list.insertBefore(row, direction < 0 ? rows[target] : rows[target].nextSibling);
                        this.save(row);
                        control.focus({ preventScroll: true });
                    },

                    restore() {
                        const byId = new Map(this.rows().map((row) => [row.getAttribute('data-sortable-id'), row]));

                        this.before.forEach((id) => {
                            const row = byId.get(id);

                            if (row) {
                                this.list.appendChild(row);
                            }
                        });
                    },

                    async save(row) {
                        const order = this.ids();
                        const label = row?.getAttribute('data-sortable-label') || this.noun;
                        const position = order.indexOf(row?.getAttribute('data-sortable-id')) + 1;

                        if (! this.url) {
                            return;
                        }

                        this.saving = true;

                        try {
                            const response = await postJson(this.url, { ...this.payload, [this.key]: order });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'The new order could not be saved.'));
                            }

                            this.announcement = `${label} moved to position ${position} of ${order.length}.`;
                        } catch (error) {
                            this.restore();
                            this.announcement = 'The order was not changed.';
                            toast('error', error.message || 'The new order could not be saved.');
                        } finally {
                            this.saving = false;
                        }
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsMenuTree — two levels, never three (INV-6)
                 | Root list [data-tree-root]; each node [data-node-id] holds one [data-tree-children].
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsMenuTree', (config = {}) => ({
                    url: config.url || null,
                    disabled: Boolean(config.disabled),
                    saving: false,
                    announcement: '',
                    dragged: null,
                    before: null,
                    root: null,

                    init() {
                        this.root = this.$el.querySelector('[data-tree-root]');
                    },

                    nodes(list) {
                        return Array.from(list.children).filter((node) => node.hasAttribute('data-node-id'));
                    },

                    childList(node) {
                        return node.querySelector(':scope [data-tree-children]');
                    },

                    hasChildren(node) {
                        const list = this.childList(node);

                        return Boolean(list && this.nodes(list).length > 0);
                    },

                    serialise() {
                        return this.nodes(this.root).map((node) => ({
                            id: Number(node.getAttribute('data-node-id')),
                            children: this.nodes(this.childList(node)).map((child) => ({
                                id: Number(child.getAttribute('data-node-id')),
                                children: [],
                            })),
                        }));
                    },

                    snapshot() {
                        return this.nodes(this.root).map((node) => [node, this.nodes(this.childList(node))]);
                    },

                    restore() {
                        if (! this.before) {
                            return;
                        }

                        this.before.forEach(([node, children]) => {
                            this.root.appendChild(node);
                            children.forEach((child) => this.childList(node).appendChild(child));
                        });

                        this.refreshDepth();
                    },

                    /** Children render indented; a top-level node shows its drop zone. */
                    refreshDepth() {
                        this.nodes(this.root).forEach((node) => node.setAttribute('data-depth', '0'));
                        this.nodes(this.root).forEach((node) => {
                            this.nodes(this.childList(node)).forEach((child) => child.setAttribute('data-depth', '1'));
                        });
                    },

                    arm(event) {
                        if (this.disabled || this.saving) {
                            return;
                        }

                        event.target.closest('[data-node-id]')?.setAttribute('draggable', 'true');
                    },

                    dragStart(event) {
                        const node = event.target.closest('[data-node-id]');

                        if (! node || node.getAttribute('draggable') !== 'true') {
                            event.preventDefault();

                            return;
                        }

                        event.stopPropagation();
                        this.dragged = node;
                        this.before = this.snapshot();
                        node.classList.add('opacity-50');
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', node.getAttribute('data-node-id'));
                    },

                    dragOver(event) {
                        if (! this.dragged) {
                            return;
                        }

                        const zone = event.target.closest('[data-tree-children]');
                        const target = event.target.closest('[data-node-id]');

                        // Dropping into an empty child zone of a top-level node.
                        if (zone && (! target || ! zone.contains(target)) && ! zone.contains(this.dragged)) {
                            if (zone.closest('[data-node-id]') === this.dragged || this.hasChildren(this.dragged)) {
                                return;
                            }

                            zone.appendChild(this.dragged);

                            return;
                        }

                        if (! target || target === this.dragged || this.dragged.contains(target)) {
                            return;
                        }

                        const list = target.parentElement;
                        const intoChildren = list !== this.root;

                        // A node with children may only ever sit at the top level.
                        if (intoChildren && this.hasChildren(this.dragged)) {
                            return;
                        }

                        const box = target.getBoundingClientRect();
                        const after = event.clientY > box.top + Math.min(box.height, 48) / 2;

                        list.insertBefore(this.dragged, after ? target.nextSibling : target);
                    },

                    dragEnd() {
                        if (! this.dragged) {
                            return;
                        }

                        this.dragged.removeAttribute('draggable');
                        this.dragged.classList.remove('opacity-50');
                        this.dragged = null;
                        this.refreshDepth();
                        this.save();
                    },

                    move(control, direction) {
                        const node = control.closest('[data-node-id]');
                        const list = node.parentElement;
                        const siblings = this.nodes(list);
                        const index = siblings.indexOf(node);
                        const target = index + direction;

                        if (target < 0 || target >= siblings.length) {
                            return;
                        }

                        this.before = this.snapshot();
                        list.insertBefore(node, direction < 0 ? siblings[target] : siblings[target].nextSibling);
                        this.save();
                        control.focus({ preventScroll: true });
                    },

                    /** Make a top-level node the last child of the node above it. */
                    indent(control) {
                        const node = control.closest('[data-node-id]');

                        if (node.parentElement !== this.root) {
                            return;
                        }

                        if (this.hasChildren(node)) {
                            toast('warning', 'Menus are two levels deep: move this item’s children out before nesting it.');

                            return;
                        }

                        const siblings = this.nodes(this.root);
                        const previous = siblings[siblings.indexOf(node) - 1];

                        if (! previous) {
                            return;
                        }

                        this.before = this.snapshot();
                        this.childList(previous).appendChild(node);
                        this.refreshDepth();
                        this.save();
                    },

                    /** Move a child back to the top level, just after its parent. */
                    outdent(control) {
                        const node = control.closest('[data-node-id]');

                        if (node.parentElement === this.root) {
                            return;
                        }

                        const parent = node.parentElement.closest('[data-node-id]');

                        this.before = this.snapshot();
                        this.root.insertBefore(node, parent.nextSibling);
                        this.refreshDepth();
                        this.save();
                    },

                    async save() {
                        if (! this.url) {
                            return;
                        }

                        const tree = this.serialise();

                        if (JSON.stringify(tree) === JSON.stringify((this.before || []).map(([node, children]) => ({
                            id: Number(node.getAttribute('data-node-id')),
                            children: children.map((child) => ({ id: Number(child.getAttribute('data-node-id')), children: [] })),
                        })))) {
                            return;
                        }

                        this.saving = true;

                        try {
                            const response = await postJson(this.url, { tree });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'The menu order could not be saved.'));
                            }

                            this.announcement = 'Menu order saved.';
                        } catch (error) {
                            this.restore();
                            this.announcement = 'The menu order was not changed.';
                            toast('error', error.message || 'The menu order could not be saved.');
                        } finally {
                            this.saving = false;
                            this.before = null;
                        }
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsMediaPicker — media_assets ids from the page's library JSON
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsMediaPicker', (config = {}) => ({
                    name: config.name,
                    multiple: Boolean(config.multiple),
                    kind: config.kind || 'image',
                    selected: Array.isArray(config.selected) ? config.selected : [],
                    open: false,
                    search: '',
                    library: [],

                    init() {
                        const source = document.getElementById(config.source || 'cms-media-library');

                        try {
                            this.library = source ? JSON.parse(source.textContent || '[]') : [];
                        } catch (error) {
                            this.library = [];
                        }

                        // After a failed submit the ids come back through old(); rebuild the chips.
                        if (Array.isArray(config.oldIds)) {
                            this.selected = config.oldIds
                                .map((id) => this.library.find((asset) => Number(asset.id) === Number(id))
                                    || this.selected.find((asset) => Number(asset.id) === Number(id)))
                                .filter(Boolean);
                        }
                    },

                    get choices() {
                        const term = this.search.trim().toLowerCase();

                        return this.library
                            .filter((asset) => (this.kind === 'video' ? asset.is_video : ! asset.is_video))
                            .filter((asset) => term === '' || `${asset.label} ${asset.alt || ''}`.toLowerCase().includes(term));
                    },

                    isSelected(id) {
                        return this.selected.some((asset) => Number(asset.id) === Number(id));
                    },

                    choose(asset) {
                        if (this.multiple) {
                            this.selected = this.isSelected(asset.id)
                                ? this.selected.filter((item) => Number(item.id) !== Number(asset.id))
                                : [...this.selected, asset];

                            return;
                        }

                        this.selected = [asset];
                        this.open = false;
                        this.$dispatch('input');
                    },

                    remove(id) {
                        this.selected = this.selected.filter((asset) => Number(asset.id) !== Number(id));
                        this.$dispatch('input');
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsUploader — per-file progress and the server's own refusal reason
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsUploader', (config = {}) => ({
                    url: config.url,
                    maxBytes: Number(config.maxBytes || 0),
                    files: [],
                    dragging: false,
                    busy: false,

                    pick(event) {
                        this.queue(event.target.files);
                        event.target.value = '';
                    },

                    dropped(event) {
                        this.dragging = false;
                        this.queue(event.dataTransfer.files);
                    },

                    queue(list) {
                        Array.from(list || []).forEach((file) => {
                            const entry = { name: file.name, size: file.size, progress: 0, state: 'queued', error: null, file };

                            if (this.maxBytes > 0 && file.size > this.maxBytes) {
                                entry.state = 'failed';
                                entry.error = `Larger than the ${Math.round(this.maxBytes / 1048576)} MB upload limit.`;
                            }

                            this.files.push(entry);
                        });

                        this.run();
                    },

                    async run() {
                        if (this.busy) {
                            return;
                        }

                        this.busy = true;

                        for (const entry of this.files) {
                            if (entry.state === 'queued') {
                                await this.send(entry);
                            }
                        }

                        this.busy = false;

                        if (this.files.some((entry) => entry.state === 'done') && this.files.every((entry) => entry.state !== 'queued')) {
                            toast('success', 'Upload finished. Refreshing the library…');
                            setTimeout(() => window.location.reload(), 900);
                        }
                    },

                    send(entry) {
                        return new Promise((resolve) => {
                            const form = new FormData(this.$refs.form);
                            form.delete('file');
                            form.append('file', entry.file);

                            const request = new XMLHttpRequest();
                            request.open('POST', this.url);
                            request.setRequestHeader('Accept', 'application/json');
                            request.setRequestHeader('X-CSRF-TOKEN', csrf());
                            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                            entry.state = 'uploading';

                            request.upload.addEventListener('progress', (event) => {
                                if (event.lengthComputable) {
                                    entry.progress = Math.round((event.loaded / event.total) * 100);
                                }
                            });

                            request.addEventListener('load', () => {
                                if (request.status >= 200 && request.status < 300) {
                                    entry.state = 'done';
                                    entry.progress = 100;
                                } else {
                                    entry.state = 'failed';

                                    try {
                                        const body = JSON.parse(request.responseText || '{}');
                                        entry.error = (body.errors ? Object.values(body.errors).flat()[0] : null) || body.message || 'The upload was refused.';
                                    } catch (error) {
                                        entry.error = request.status === 413 ? 'The file is larger than the server accepts.' : 'The upload was refused.';
                                    }
                                }

                                entry.file = null;
                                resolve();
                            });

                            request.addEventListener('error', () => {
                                entry.state = 'failed';
                                entry.error = 'The connection dropped before the upload finished.';
                                resolve();
                            });

                            request.send(form);
                        });
                    },

                    humanSize(bytes) {
                        if (bytes >= 1048576) {
                            return `${(bytes / 1048576).toFixed(1)} MB`;
                        }

                        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsSlug — live /{slug}, auto from title, reserved-word hint
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsSlug', (config = {}) => ({
                    slug: config.slug || '',
                    auto: Boolean(config.auto),
                    reserved: Array.isArray(config.reserved) ? config.reserved : [],
                    locked: Boolean(config.locked),

                    slugify(value) {
                        return String(value || '')
                            .normalize('NFKD')
                            .replace(/[\u0300-\u036f]/g, '')
                            .toLowerCase()
                            .replace(/[^a-z0-9]+/g, '-')
                            .replace(/^-+|-+$/g, '')
                            .slice(0, 200);
                    },

                    fromTitle(value) {
                        if (this.auto && ! this.locked) {
                            this.slug = this.slugify(value);
                        }
                    },

                    get isReserved() {
                        return this.slug !== '' && this.reserved.includes(this.slug);
                    },

                    get isMalformed() {
                        return this.slug !== '' && ! /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(this.slug);
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsLengthMeter — counts the characters of one field
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsLengthMeter', (config = {}) => ({
                    count: 0,
                    max: Number(config.max || 0),
                    idealMin: config.idealMin === undefined ? null : Number(config.idealMin),
                    idealMax: config.idealMax === undefined ? null : Number(config.idealMax),

                    init() {
                        const field = document.getElementById(config.for);

                        if (! field) {
                            return;
                        }

                        const update = () => {
                            this.count = String(field.value || '').length;
                        };

                        update();
                        field.addEventListener('input', update);
                        field.addEventListener('change', update);
                    },

                    get tone() {
                        if (this.max > 0 && this.count > this.max) {
                            return 'rose';
                        }

                        if (this.idealMax !== null && this.count > this.idealMax) {
                            return 'amber';
                        }

                        if (this.idealMin !== null && this.count > 0 && this.count < this.idealMin) {
                            return 'amber';
                        }

                        if (this.max > 0 && this.count >= Math.floor(this.max * 0.9)) {
                            return 'amber';
                        }

                        return this.count === 0 ? 'slate' : 'emerald';
                    },

                    get percent() {
                        const ceiling = this.max > 0 ? this.max : (this.idealMax || 1);

                        return Math.min(100, Math.round((this.count / ceiling) * 100));
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsBulk — one confirmed action, one POST per selected row, then reload
                 | `template` contains the placeholder id 987654321, replaced per row.
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsBulk', () => ({
                    running: false,
                    selected: [],

                    toggleAll(checked) {
                        this.selected = checked
                            ? Array.from(this.$root.querySelectorAll('[data-bulk-id]')).map((box) => box.value)
                            : [];
                    },

                    async run(template, ids, payload = {}, noun = 'item') {
                        if (this.running || ! ids.length) {
                            return;
                        }

                        this.running = true;
                        let done = 0;
                        const failures = [];

                        for (const id of ids) {
                            try {
                                const response = await postJson(String(template).replace('987654321', String(id)), payload);

                                if (response.ok) {
                                    done++;
                                } else {
                                    failures.push(await messageFrom(response, `#${id} was refused.`));
                                }
                            } catch (error) {
                                failures.push(`#${id}: the request failed.`);
                            }
                        }

                        this.running = false;

                        if (failures.length) {
                            toast('error', `${failures.length} ${noun}${failures.length === 1 ? '' : 's'} not changed: ${failures[0]}`);
                        }

                        if (done > 0) {
                            toast('success', `${done} ${noun}${done === 1 ? '' : 's'} updated.`);
                            setTimeout(() => window.location.reload(), 700);
                        }
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsDirty — the sticky publish bar appears only when something changed
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsDirty', (config = {}) => ({
                    dirty: Boolean(config.dirty),
                    submitting: false,

                    init() {
                        const mark = (event) => {
                            if (event.target && event.target.closest && event.target.closest('[data-dirty-ignore]')) {
                                return;
                            }

                            this.dirty = true;
                        };

                        this.$el.addEventListener('input', mark);
                        this.$el.addEventListener('change', mark);
                        this.$el.addEventListener('trix-change', mark);

                        window.addEventListener('beforeunload', (event) => {
                            if (this.dirty && ! this.submitting) {
                                event.preventDefault();
                                event.returnValue = '';
                            }
                        });
                    },

                    submitted() {
                        this.submitting = true;
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsFetchList — a lazily loaded JSON list (usage, link check)
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsFetchList', (config = {}) => ({
                    url: config.url,
                    key: config.key || null,
                    items: [],
                    open: false,
                    loaded: false,
                    loading: false,
                    error: null,

                    async load(force = false) {
                        if (! this.url || this.loading || (this.loaded && ! force)) {
                            return;
                        }

                        this.loading = true;
                        this.error = null;

                        try {
                            const response = await fetch(this.url, {
                                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin',
                            });

                            if (! response.ok) {
                                throw new Error(await messageFrom(response, 'Could not load the list.'));
                            }

                            const body = await response.json();
                            const list = this.key ? body[this.key] : (Array.isArray(body) ? body : (body.data || body.usage || body.items || []));

                            this.items = Array.isArray(list) ? list : Object.values(list || {});
                            this.loaded = true;
                        } catch (error) {
                            this.error = error.message;
                        } finally {
                            this.loading = false;
                        }
                    },
                }));

                /* ------------------------------------------------------------------------------
                 | cmsCopy — copy a value, or a URL fetched from a JSON endpoint, to the clipboard
                 ------------------------------------------------------------------------------ */
                Alpine.data('cmsCopy', (config = {}) => ({
                    value: config.value || null,
                    url: config.url || null,
                    copied: false,

                    async copy() {
                        try {
                            let text = this.value;

                            if (! text && this.url) {
                                const response = await fetch(this.url, {
                                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    credentials: 'same-origin',
                                });

                                if (! response.ok) {
                                    throw new Error(await messageFrom(response, 'Could not create the link.'));
                                }

                                const body = await response.json();
                                text = body.url || '';
                            }

                            if (! text) {
                                throw new Error('Nothing to copy.');
                            }

                            await navigator.clipboard.writeText(text);
                            this.copied = true;
                            toast('success', 'Link copied to the clipboard.');
                            setTimeout(() => { this.copied = false; }, 2000);
                        } catch (error) {
                            toast('error', error.message || 'Could not copy the link.');
                        }
                    },
                }));
            });
        </script>
    @endpush
@endonce
