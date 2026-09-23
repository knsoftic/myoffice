{{--
    The one print-template form, shared by create and edit (phase-19-23 §6.13, §7.6).

    **The body is a plain textarea, not a rich editor, and that is the point.** What is stored is HTML
    with `{tokens}` in it — never Blade — and it is sanitised on save and again on render (D-21-2,
    INV-21-5). A WYSIWYG would hide the one thing the person writing a template needs to see, which is
    exactly which tags and attributes survive.

    **The token list is generated from `PrintTokenRegistry`**, so it can never drift from what the
    renderer replaces. Clicking one inserts it at the cursor; nothing here validates token names,
    because the server names the unknown ones back in a warning after the save — losing an hour of
    layout work to a typo would be the worse trade.

    **`type` is chosen once.** On edit it is shown and not submitted: the token list, the paper and
    every document already printed depend on it, and "turn this certificate into an ID card" is not
    an edit, it is a different template.
--}}
@php($editing = isset($template))
@php($needsReason = $needsReason ?? false)
@php($current = $editing ? $template : null)
@php($signatories = old('signatories', $editing ? (array) ($template->signatories ?? []) : []))

<div class="grid gap-6">
    <x-ui.card>
        <x-ui.section-heading title="What this template prints"
                              subtitle="The kind decides which tokens it may use and what paper it starts on." />

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-ui.form.label>Kind</x-ui.form.label>
                <div class="mt-1.5">
                    <x-ui.badge :color="$type->color()">{{ $type->label() }}</x-ui.badge>
                </div>
                <x-ui.form.help>
                    {{ $type->description() }}
                    @if (! $editing)
                        Pick a different kind from the links above; it decides the token list.
                    @else
                        A template keeps the kind it was created with — the tokens it uses and the documents
                        already printed with it both depend on it.
                    @endif
                </x-ui.form.help>

                {{-- Submitted only when creating. On edit the controller strips it anyway, and a field
                     that posts a value the server discards is a control that lies about what it does. --}}
                @unless ($editing)
                    <input type="hidden" name="type" value="{{ $type->value }}">
                @endunless
            </div>

            <x-ui.form.input name="code" label="Code" required
                             :value="old('code', $current?->code)"
                             placeholder="DEFAULT-CERTIFICATE"
                             help="Letters, digits, dashes and underscores. Unique." />

            <x-ui.form.input name="name" label="Name" required
                             :value="old('name', $current?->name)"
                             placeholder="Standard certificate" />

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="description" label="Description" rows="2"
                                    :value="old('description', $current?->description)"
                                    help="For the office, not for the printed page." />
            </div>

            <x-ui.form.select name="branch_id" label="Branch" placeholder="Every branch"
                              help="A branch with its own letterhead can have its own template.">
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) old('branch_id', $current?->branch_id) === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.form.select>
        </div>
    </x-ui.card>

    <x-ui.card x-data="{ paper: @js(old('paper_size', $current?->paper_size->value ?? $type->defaultPaperSize()->value)) }">
        <x-ui.section-heading title="Paper"
                              subtitle="A card printer accepts CR80 and nothing else; a certificate reads across an A4 page." />

        <div class="grid gap-4 sm:grid-cols-4">
            <x-ui.form.select name="paper_size" label="Size" required x-model="paper">
                @foreach ($paperSizes as $size)
                    <option value="{{ $size->value }}">{{ $size->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="orientation" label="Orientation" required>
                @foreach ($orientations as $orientation)
                    <option value="{{ $orientation->value }}"
                        @selected(old('orientation', $current?->orientation->value ?? $type->defaultOrientation()->value) === $orientation->value)>
                        {{ $orientation->label() }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div x-show="paper === 'custom'" x-cloak>
                <x-ui.form.input type="number" step="0.01" min="1" max="2000"
                                 name="width_mm" label="Width" suffix="mm"
                                 :value="old('width_mm', $current?->width_mm)" />
            </div>

            <div x-show="paper === 'custom'" x-cloak>
                <x-ui.form.input type="number" step="0.01" min="1" max="2000"
                                 name="height_mm" label="Height" suffix="mm"
                                 :value="old('height_mm', $current?->height_mm)" />
            </div>

            <x-ui.form.input type="number" step="0.01" min="0" max="100"
                             name="margin_mm" label="Margin" suffix="mm"
                             :value="old('margin_mm', $current?->margin_mm ?? '10.00')"
                             help="Set to 0 if the design draws its own edge." />

            <x-ui.form.input type="number" name="sort_order" label="Order" min="0" max="9999"
                             :value="old('sort_order', $current?->sort_order ?? 0)" />

            <div class="flex items-end">
                <x-ui.form.toggle name="is_active" label="In use"
                                  description="A retired template still reprints what it printed."
                                  :checked="(bool) old('is_active', $current?->is_active ?? true)" />
            </div>
        </div>
    </x-ui.card>

    @if ($type->supportsQr())
        <x-ui.card x-data="{ qr: @js((bool) old('show_qr', $current?->show_qr ?? true)) }">
            <x-ui.section-heading title="Verification code"
                                  subtitle="The QR code resolves to the public verification page. Leave it out only if the design carries the code in words." />

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.form.toggle name="show_qr" label="Print a QR code" x-model="qr"
                                  :checked="(bool) old('show_qr', $current?->show_qr ?? true)" />

                <div x-show="qr" x-cloak>
                    <x-ui.form.input type="number" step="0.01" min="5" max="100"
                                     name="qr_size_mm" label="Size" suffix="mm"
                                     :value="old('qr_size_mm', $current?->qr_size_mm ?? '25.00')"
                                     help="Under 15 mm is hard to scan from a photocopy." />
                </div>
            </div>
        </x-ui.card>
    @endif

    @if ($type === \App\Enums\PrintTemplateType::Certificate)
        <x-ui.card>
            <x-ui.section-heading title="Signatories"
                                  subtitle="Up to three. A slot left empty prints nothing — the tokens do not show through." />

            <div class="grid gap-4">
                @foreach ([0, 1, 2] as $slot)
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.form.input :name="'signatories['.$slot.'][name]'"
                                         :label="'Signatory '.($slot + 1).' — name'"
                                         :value="data_get($signatories, $slot.'.name')"
                                         placeholder="Dr Nadia Khan" />
                        <x-ui.form.input :name="'signatories['.$slot.'][title]'"
                                         :label="'Signatory '.($slot + 1).' — title'"
                                         :value="data_get($signatories, $slot.'.title')"
                                         placeholder="Director" />
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <x-ui.card x-data="{
        insert(token) {
            const field = this.$refs.body;
            const at = field.selectionStart ?? field.value.length;
            field.value = field.value.slice(0, at) + token + field.value.slice(field.selectionEnd ?? at);
            field.focus();
            field.selectionStart = field.selectionEnd = at + token.length;
        },
    }">
        <x-ui.section-heading title="The layout"
                              subtitle="HTML with {tokens} in it. Scripts, iframes and Blade are stripped on save — what you see below is what a PDF is built from." />

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.form.label for="body_html" required>Body</x-ui.form.label>
                <textarea id="body_html" name="body_html" rows="20" x-ref="body" required
                          class="mt-1 block w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">{{ old('body_html', $current?->body_html) }}</textarea>
                <x-ui.form.error for="body_html" />

                <div class="mt-4">
                    <x-ui.form.textarea name="custom_css" label="Stylesheet" rows="8"
                                        :value="old('custom_css', $current?->custom_css)"
                                        class="font-mono"
                                        help="Plain CSS. Only class names from the allowed list survive on the HTML, so style by element or by one of those." />
                </div>
            </div>

            <div>
                <x-ui.form.label>Tokens</x-ui.form.label>
                <p class="mb-2 mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Click one to put it where the cursor is. Anything else in braces prints nothing.
                </p>

                <div class="max-h-[34rem] space-y-4 overflow-y-auto rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    @foreach ($tokens as $group => $groupTokens)
                        <div>
                            <h4 class="mb-1 text-2xs font-semibold uppercase tracking-wide text-slate-400">
                                {{ $groups[$group] ?? $group }}
                            </h4>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($groupTokens as $token => $spec)
                                    <button type="button"
                                            x-on:click="insert('{{ '{'.$token.'}' }}')"
                                            title="{{ $spec['label'] }} — e.g. {{ $spec['example'] }}"
                                            class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-2xs text-slate-600 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
                                        {{ '{'.$token.'}' }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-ui.card>

    @if ($needsReason)
        <x-ui.card>
            <x-ui.section-heading title="Why this change?"
                                  subtitle="Documents have already been printed with this template. The change is logged with the reason and a diff." />

            <x-ui.form.input name="reason" label="Reason" required
                             :value="old('reason')"
                             placeholder="Corrected the director's title" />
        </x-ui.card>
    @endif
</div>
