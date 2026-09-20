@php
    /** @var \App\Models\Collaborator\Collaborator|null $collaborator */
    $collaborator = $collaborator ?? null;
    $serviceIds = $serviceIds ?? [];
    $currentSkills = $collaborator?->skills->pluck('name')->all() ?? [];
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <x-ui.card title="Who they are">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.input name="name" label="Contact name" required maxlength="150"
                    :value="old('name', $collaborator?->name)" />
                <x-ui.form.input name="company_name" label="Company" maxlength="150"
                    :value="old('company_name', $collaborator?->company_name)"
                    help="Shown in place of the contact name wherever there is one." />
                <x-ui.form.select name="collaboration_type" label="Type" :options="$types" required
                    :selected="old('collaboration_type', $collaborator?->collaboration_type?->value)" />
                <x-ui.form.input type="date" name="joining_date" label="Joining date"
                    :value="old('joining_date', $collaborator?->joining_date ? app_date($collaborator->joining_date, 'Y-m-d') : null)" />
            </div>
        </x-ui.card>

        <x-ui.card title="How to reach them">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.input type="email" name="email" label="Email" maxlength="150"
                    :value="old('email', $collaborator?->email)"
                    help="Becomes their panel login when the application is approved." />
                <x-ui.form.input name="phone" label="Phone" maxlength="32" :value="old('phone', $collaborator?->phone)" />
                <x-ui.form.input name="whatsapp" label="WhatsApp" maxlength="32" :value="old('whatsapp', $collaborator?->whatsapp)" />
                <x-ui.form.input name="country" label="Country" maxlength="100" :value="old('country', $collaborator?->country)" />
                <div class="sm:col-span-2">
                    <x-ui.form.input name="address" label="Address" maxlength="255" :value="old('address', $collaborator?->address)" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="What they do" subtitle="Skills are free text; services come from the catalogue you already sell.">
            <div class="space-y-3">
                <x-ui.form.textarea name="skills_text" label="Skills" rows="2"
                    :value="old('skills_text', implode(', ', $currentSkills))"
                    help="Comma separated. React, Laravel, SEO — the set replaces what is there." />

                @if ($services->isNotEmpty())
                    <div>
                        <x-ui.form.label for="service_ids">Services offered</x-ui.form.label>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($services as $service)
                                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" name="service_ids[]" value="{{ $service->id }}"
                                        @checked(in_array($service->id, old('service_ids', $serviceIds), false))
                                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                                    {{ $service->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-4">
        @if ($collaborator === null)
            <x-ui.card title="Code and state">
                @if ($codeIsEditable)
                    <x-ui.form.input name="referral_code" label="Referral code" maxlength="32" :value="old('referral_code')"
                        help="Leave empty to use the collaborator ID. A code cannot be changed once anything references it." />
                @endif
                @can('collaborators.approve')
                    <div class="mt-3">
                        <x-ui.form.checkbox name="activate" label="Approve immediately" :checked="(bool) old('activate')" with-hidden />
                        <x-ui.form.help>
                            Skips the application queue. The panel account and the invitation are created on the
                            collaborator screen afterwards.
                        </x-ui.form.help>
                    </div>
                @endcan
            </x-ui.card>
        @endif

        <x-ui.card title="Internal notes" subtitle="Never shown in the collaborator's own panel.">
            <x-ui.form.textarea name="notes" label="Notes" rows="6" :value="old('notes', $collaborator?->notes)" />
            @if ($collaborator !== null)
                <div class="mt-3">
                    <x-ui.form.input name="reason" label="Reason for this edit" maxlength="255" :value="old('reason')"
                        help="Optional, and it goes on the audit trail." />
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
