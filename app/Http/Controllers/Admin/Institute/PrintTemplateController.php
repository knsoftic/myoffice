<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StorePrintTemplateRequest;
use App\Models\Branch;
use App\Models\Institute\PrintTemplate;
use App\Services\Institute\PrintTemplateService;
use App\Support\PrintTokenRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Designing printable documents — `admin.print-templates.*` (§82, §84, §85, phase-19-23 §7.6).
 *
 * **The preview is the interesting screen and it holds no student data.** It renders with
 * `PrintTokenRegistry`'s example values, which is what makes `print_templates` safe to grant to a
 * designer who should never see a student record — the whole reason §4.1 makes it a module of its
 * own.
 *
 * **Unknown tokens come back as a warning, not a refusal.** A designer gets a typo wrong on the way
 * to getting a layout right, and losing an hour of work to a rejected save is worse than a token that
 * prints nothing. The warning names each one, because "there is an unknown token somewhere" sends
 * somebody hunting through two hundred lines.
 */
final class PrintTemplateController extends Controller
{
    public function __construct(
        private readonly PrintTemplateService $templates,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PrintTemplate::class);

        $templates = PrintTemplate::query()
            ->with('branch:id,name')
            ->when($request->string('q')->toString() !== '', function (Builder $query) use ($request): void {
                $term = $request->string('q')->toString();
                $query->where(fn (Builder $q) => $q->where('name', 'like', "%$term%")->orWhere('code', 'like', "%$term%"));
            })
            ->when($request->string('type')->toString() !== '', fn (Builder $q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->string('status')->toString() === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($request->boolean('trashed'), fn (Builder $q) => $q->onlyTrashed())
            ->ordered()
            ->paginate(20)
            ->withQueryString();

        return view('admin.print-templates.index', [
            'templates' => $templates,
            'types' => PrintTemplateType::cases(),
            'canCreate' => (bool) $request->user()?->can('create', PrintTemplate::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', PrintTemplate::class);

        $type = PrintTemplateType::tryFrom($request->string('type')->toString()) ?? PrintTemplateType::Certificate;

        return view('admin.print-templates.create', $this->formData($type));
    }

    public function store(StorePrintTemplateRequest $request): RedirectResponse
    {
        [$template, $unknown] = $this->templates->create($request->validated(), $request->user());

        return redirect()
            ->route('admin.print-templates.edit', $template)
            ->with('toast', $this->toastFor('Template created.', $unknown));
    }

    public function show(Request $request, PrintTemplate $print_template): View
    {
        Gate::authorize('view', $print_template);

        return view('admin.print-templates.show', [
            'template' => $print_template->load('branch:id,name'),
            'tokens' => PrintTokenRegistry::grouped($print_template->type),
            'usedBy' => [
                'certificates' => $print_template->certificates()->count(),
                'cards' => $print_template->idCards()->count(),
            ],
            'canEdit' => (bool) $request->user()?->can('update', $print_template),
            'canChangeStatus' => (bool) $request->user()?->can('changeStatus', $print_template),
            'canDelete' => (bool) $request->user()?->can('delete', $print_template),
            'canPreview' => (bool) $request->user()?->can('print', $print_template),
        ]);
    }

    public function edit(Request $request, PrintTemplate $print_template): View
    {
        Gate::authorize('update', $print_template);

        return view('admin.print-templates.edit', array_merge($this->formData($print_template->type), [
            'template' => $print_template,
            // A template with documents behind it stays editable — an issued certificate keeps its
            // own snapshots — but the service demands a reason and logs a diff, so the screen asks
            // for one rather than letting the save fail.
            'needsReason' => $print_template->hasPrintedAnything(),
            'unknownTokens' => PrintTokenRegistry::unknownIn($print_template->type, $print_template->getAttribute('body_html')),
        ]));
    }

    public function update(StorePrintTemplateRequest $request, PrintTemplate $print_template): RedirectResponse
    {
        [, $unknown] = $this->templates->update(
            $print_template,
            $request->safe()->except(['reason', 'type']),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('toast', $this->toastFor('Template updated.', $unknown));
    }

    /**
     * The preview, rendered with example values.
     *
     * Returned as HTML rather than a PDF: adjusting a margin should not cost a dompdf render each
     * time, and the browser shows the same markup the renderer will be handed.
     */
    public function preview(Request $request, PrintTemplate $print_template): Response
    {
        Gate::authorize('print', $print_template);

        $html = $this->templates->preview($print_template);

        [$width, $height] = $print_template->dimensionsMm();

        return response()->view('admin.print-templates.preview', [
            'template' => $print_template,
            'body' => $html,
            'widthMm' => $width,
            'heightMm' => $height,
        ]);
    }

    public function setDefault(Request $request, PrintTemplate $print_template): RedirectResponse
    {
        Gate::authorize('changeStatus', $print_template);

        $this->templates->setDefault($print_template, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'This is the default now — documents that name no template of their own will use it.',
        ]);
    }

    public function deactivate(Request $request, PrintTemplate $print_template): RedirectResponse
    {
        Gate::authorize('changeStatus', $print_template);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->templates->deactivate($print_template, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Retired. Documents already printed with it can still be reprinted exactly as they were.',
        ]);
    }

    public function destroy(Request $request, PrintTemplate $print_template): RedirectResponse
    {
        Gate::authorize('delete', $print_template);

        $print_template->delete();

        return redirect()
            ->route('admin.print-templates.index')
            ->with('toast', ['type' => 'success', 'message' => 'Template removed.']);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Success, or success with a list of tokens that will print nothing.
     *
     * @param  list<string>  $unknown
     * @return array<string, string>
     */
    private function toastFor(string $message, array $unknown): array
    {
        if ($unknown === []) {
            return ['type' => 'success', 'message' => $message];
        }

        $named = implode(', ', array_map(static fn (string $token): string => '{'.$token.'}', $unknown));

        return [
            'type' => 'warning',
            'message' => count($unknown) === 1
                ? sprintf('%s %s is not a token this template knows, so it will print nothing.', $message, $named)
                : sprintf('%s These are not tokens this template knows, so they will print nothing: %s.', $message, $named),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(PrintTemplateType $type): array
    {
        return [
            'type' => $type,
            'types' => PrintTemplateType::cases(),
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'tokens' => PrintTokenRegistry::grouped($type),
            'groups' => PrintTokenRegistry::GROUPS,
        ];
    }
}
