<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\PrintTemplate;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may design a printable document (phase-19-23 §9.3, §4.1, INV-21-5).
 *
 * **`print_templates` is a module of its own precisely because `body_html` is powerful.** §4.1 makes
 * it separately grantable so a designer can be handed the certificate layout with no sight of a
 * student record at all — and, just as importantly, so somebody who issues certificates all day is
 * not thereby given the ability that decides what HTML a PDF renderer is handed. The two jobs have
 * nothing to do with each other and only one of them is dangerous.
 *
 * It is dangerous in a specific, bounded way. A template is HTML with `{tokens}`, sanitised on save
 * and again on render and replaced by `str_replace` — never compiled, never evaluated (INV-21-5, and
 * [D-21-2] is the reason). So the worst a template author can do is produce an ugly or a misleading
 * document, not execute anything. That is why this is a policy at all rather than a hard refusal:
 * the containment is in `PrintTemplateService`, and this only decides who gets to use it.
 *
 * **A template that has printed something is retired, never deleted.** `restrictOnDelete` from
 * `certificates` and `student_id_cards` catches the hard delete and the model's hook catches the soft
 * one — D124's shape. `delete()` below is the version a screen reads before offering a button.
 */
final class PrintTemplatePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'print_templates';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, PrintTemplate $template): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($template));
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing.
     *
     * **A template with issued documents behind it is still editable**, deliberately — a layout gets
     * adjusted, and an issued certificate keeps its own snapshots and is unaffected in content. What
     * the service adds is a mandatory reason and a logged diff, so a reprint that looks different
     * from the original is explicable a year later. That is a rule about *evidence*, which a policy
     * cannot demand, so it lives where it can.
     */
    public function update(User $user, PrintTemplate $template): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && ! $this->isTrashed($template)
            && $this->sharesBranch($user, $this->branchOf($template));
    }

    /** Making one the default for its (type, branch), and retiring one. */
    public function changeStatus(User $user, PrintTemplate $template): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($template)
            && $this->sharesBranch($user, $this->branchOf($template));
    }

    /**
     * The preview.
     *
     * It renders with `PrintTokenRegistry`'s example values and **never a real student's data**, so
     * this gates a screen that holds no student record — which is the whole reason a designer can be
     * given this module and nothing else.
     */
    public function print(User $user, PrintTemplate $template): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print)
            && $this->sharesBranch($user, $this->branchOf($template));
    }

    /**
     * **Only a template nobody has printed with.** The model refuses the rest — including the soft
     * delete that `restrictOnDelete` never sees — and this is the version a screen can read before
     * offering a button that would throw.
     */
    public function delete(User $user, PrintTemplate $template): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && ! $this->isTrashed($template)
            && ! $template->hasPrintedAnything()
            && $this->sharesBranch($user, $this->branchOf($template));
    }

    /**
     * Never registered, so never granted.
     *
     * `print_templates` declares `CRUD + STATUS + print` and `CRUD` carries no `restore` — a template
     * is retired rather than deleted, so there is nothing to bring back. The method exists because
     * Laravel asks for it and answering false is clearer than leaving it to fall through.
     */
    public function restore(User $user, PrintTemplate $template): bool
    {
        return false;
    }

    public function forceDelete(User $user, PrintTemplate $template): bool
    {
        return false;
    }

    private function branchOf(PrintTemplate $template): ?int
    {
        $branchId = $template->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
