<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Enums\ThemePreference;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * `user:create-super-admin` — the first account, created without a password ever existing as an
 * argument (phase-24-25 section 6.8 step 9, section 6.6).
 *
 * **The command generates the password; there is no option to supply one, and adding one would be a
 * regression.** A password passed as an argument is a password in the operator's shell history, in
 * `ps` output for as long as the process lives, in the CI job log, and in the deploy transcript
 * somebody pastes into a ticket. Every one of those outlives the install. The generated value never
 * leaves this process except by the two routes below, and neither of them is a log.
 *
 * **Without `--show-password` nothing secret is printed at all.** The account is created flagged
 * `must_change_password`, a password-reset link is mailed to the address given, and the operator
 * finishes the install through that link — so the only copy of a working credential is in the
 * mailbox of the person who is meant to own the account. `--show-password` exists for the offline
 * install of section 6.8 step 9, where no mail transport is configured yet: it prints the generated
 * value **once**, to stdout, says so, and that is the only time it is ever shown. It is deliberately
 * never written to the log, the activity log, or the notification table.
 *
 * **`--force` is required to create a second Super Admin.** The normal system has exactly one, and
 * `Gate::before` short-circuits every check for that role (CLAUDE.md section 4), so an accidental
 * second one is a second person who can do anything, silently. Making that deliberate costs one flag
 * and buys the difference between "we have one" and "we think we have one".
 *
 * **It is idempotent on the e-mail address.** Re-running it promotes the existing account rather
 * than colliding on `users.email` — and it says which of the two it did, because "created" and
 * "promoted an account that was already here" are very different sentences during an install. A
 * promoted account keeps its own password: this command never resets a live credential, so running
 * it twice cannot lock out a working administrator.
 *
 * Example and disposable domains are refused. An install that reaches production with
 * `admin@example.com` as the one account that can do anything has no route to a password reset, and
 * the failure surfaces months later as "nobody can get in".
 *
 * Exit codes (the contract a deploy script reads):
 *   0  the account exists, is active, holds Super Admin, and must change its password
 *   1  refused — a second Super Admin without `--force`, a missing or unusable e-mail address, or
 *      the write failed. Nothing was created.
 */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'user:create-super-admin
                            {--name= : The real name of the person who will own the account}
                            {--email= : Their real e-mail address — the reset link goes here}
                            {--show-password : Print the generated password once, for an offline install}
                            {--force : Allow a second Super Admin to exist}';

    protected $description = 'Create (or promote) the first Super Admin. The password is generated, never supplied.';

    /**
     * 24 characters, per section 6.8 step 9.
     *
     * Letters and digits only, no symbols: the offline path has somebody reading this off a console
     * and typing it into a browser, and a symbol is where that goes wrong — either mistyped, or
     * mangled by the shell of whoever copies the command that printed it.
     */
    private const PASSWORD_LENGTH = 24;

    /**
     * Domains that cannot receive a password reset.
     *
     * `example.*` is reserved by RFC 2606 and can never accept mail; the rest are disposable
     * mailboxes that stop existing. Either way the one account that can do anything ends up with no
     * recovery route, which is discovered at the worst possible moment.
     *
     * @var list<string>
     */
    private const UNUSABLE_DOMAINS = [
        'example.com', 'example.net', 'example.org', 'example.edu',
        'test', 'invalid', 'localhost',
        'mailinator.com', 'yopmail.com', 'guerrillamail.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'trashmail.com', 'sharklasers.com',
        'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com',
    ];

    public function handle(PasswordBroker $passwords): int
    {
        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $name = $this->resolveName();

        if ($name === null) {
            return self::FAILURE;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        $role = Role::query()
            ->where('name', User::SUPER_ADMIN_ROLE)
            ->where('guard_name', $guard)
            ->first();

        if ($role === null) {
            $this->error(sprintf(
                'The "%s" role does not exist yet. Run the reference-data seeder first '
                .'(section 6.8 step 8: php artisan db:seed --force) — a Super Admin without the role '
                .'the Gate short-circuits for is just an ordinary user with a misleading name.',
                User::SUPER_ADMIN_ROLE,
            ));

            return self::FAILURE;
        }

        // withTrashed: users are soft-deleted but `email` is unique, so a trashed row would make a
        // plain insert collide on the index rather than reach the idempotent branch below.
        $existing = User::withTrashed()->where('email', $email)->first();

        if (! $this->assertNoOtherSuperAdmin($guard, $existing)) {
            return self::FAILURE;
        }

        // Generated even when the account already exists, and then deliberately thrown away: the
        // password of a live account is never reset by this command.
        $password = Str::password(self::PASSWORD_LENGTH, true, true, false, false);

        try {
            $created = DB::transaction(
                fn (): bool => $this->write($existing, $email, $name, $password, $role),
            );
        } catch (Throwable $e) {
            $this->error('Nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->report($email, $name, $password, $created);

        if ($created && ! $this->option('show-password')) {
            $this->sendResetLink($passwords, $email);
        }

        return self::SUCCESS;
    }

    /**
     * Create the account, or bring an existing one up to Super Admin.
     *
     * The two paths are deliberately asymmetric. A new row gets the generated password and the
     * forced-change flag. An existing row is promoted — active, verified, branch attached, role
     * granted — and its password is not touched, because the person using it right now would be
     * locked out by a reset they did not ask for.
     */
    private function write(?User $existing, string $email, string $name, string $password, Role $role): bool
    {
        $user = $existing;
        $created = $user === null;

        if ($user === null) {
            $user = new User;
            $user->email = $email;
            $user->password = $password;          // hashed by the model's cast
            $user->password_changed_at = null;
            $user->locale = (string) config('app.locale', 'en');
        }

        if ($user->trashed()) {
            // Restoring is not destructive, and an install that found a soft-deleted account at this
            // address needs a usable one, not a second row it cannot create.
            $user->restore();
        }

        $user->name = $name;
        $user->status = UserStatus::Active;
        $user->status_reason = null;

        // `must_change_password` is a set-never-filled security flag (User::class, SEC-12). It is set
        // on both paths on purpose: a generated password must be replaced by a human-chosen one at
        // first sign-in, and an account being promoted to "can do anything" is exactly the moment to
        // make its owner prove they still hold it.
        $user->must_change_password = true;

        if ($user->status_changed_at === null) {
            $user->status_changed_at = now();
        }

        if ($user->theme === null) {
            $user->theme = ThemePreference::System;
        }

        if ($user->email_verified_at === null) {
            // Step 9's acceptance criterion. The address was typed by the installer, and a first
            // account stuck behind its own verification mail cannot get in to configure the mailer
            // that would have sent it.
            $user->email_verified_at = now();
        }

        if ($user->branch_id === null) {
            $user->branch_id = Branch::default()?->getKey();
        }

        $user->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $created;
    }

    /**
     * The one printed report — and the one place the generated password can appear.
     */
    private function report(string $email, string $name, string $password, bool $created): void
    {
        $this->newLine();
        $this->table(['', ''], [
            ['action', $created ? 'created a new account' : 'promoted the existing account at this address'],
            ['name', $name],
            ['email', $email],
            ['role', User::SUPER_ADMIN_ROLE],
            ['must change password', 'yes'],
        ]);
        $this->newLine();

        if (! $created) {
            $this->warn(
                'An account already existed at this address, so its password was NOT changed — this '
                .'command never resets a live credential. If nobody holds it, use the '
                .'forgot-password screen.'
            );

            return;
        }

        if (! $this->option('show-password')) {
            $this->info('No password was printed. A reset link is being e-mailed to '.$email.'.');

            return;
        }

        $this->warn('================ GENERATED PASSWORD ================');
        $this->line('  '.$password);
        $this->warn('  Shown once, here, and nowhere else. It is not in the log, not in the activity');
        $this->warn('  log, and not recoverable — copy it now. The account must replace it at first');
        $this->warn('  sign-in. Close this terminal when you are done.');
        $this->warn('====================================================');
    }

    /**
     * Mail the reset link instead of printing anything.
     *
     * The broker builds and mails the signed link (`password.reset`, valid for
     * `auth.passwords.users.expire` minutes — 60 by default, per step 9). The token deliberately does
     * not pass through this command: a token echoed to stdout is a credential in the deploy log,
     * which is the whole failure this command exists to avoid.
     *
     * A send failure is reported and does not fail the command. The account is already correct, and
     * on a fresh install mail is usually configured two steps later (section 6.8 step 17) — so the
     * useful output is the recovery instruction, not a non-zero exit that makes a runbook look broken.
     */
    private function sendResetLink(PasswordBroker $passwords, string $email): void
    {
        try {
            $status = $passwords->sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            $status = $e->getMessage();
        }

        if ($status === PasswordBroker::RESET_LINK_SENT) {
            $this->info('Reset link sent. It expires in '.(int) config('auth.passwords.users.expire', 60).' minutes.');

            return;
        }

        $this->warn(
            'The reset link could not be sent ('.$status.'). The account is correct and flagged for a '
            .'forced password change; finish it with the forgot-password screen once Settings > Mail is '
            .'configured (section 6.8 step 17), or re-run this command on a fresh address with '
            .'--show-password for an offline install.'
        );
    }

    /**
     * Refuse a second Super Admin unless `--force` says otherwise.
     *
     * The account being created or promoted is excluded from the count, so re-running the command for
     * the same address stays idempotent instead of refusing itself on the second run.
     */
    private function assertNoOtherSuperAdmin(string $guard, ?User $target): bool
    {
        $others = User::withTrashed()
            ->when($target !== null, fn ($q) => $q->whereKeyNot($target->getKey()))
            ->whereHas('roles', fn ($q) => $q
                ->where('name', User::SUPER_ADMIN_ROLE)
                ->where('guard_name', $guard))
            ->get(['id', 'name', 'email']);

        if ($others->isEmpty() || $this->option('force')) {
            if ($others->isNotEmpty()) {
                $this->warn(sprintf(
                    '--force: %d other Super Admin account(s) already exist. Every one of them can do '
                    .'anything, in every module, for ever.',
                    $others->count(),
                ));
            }

            return true;
        }

        $this->error('A Super Admin already exists, so this command refused to create a second one.');
        $this->newLine();
        $this->table(
            ['existing Super Admin', 'email'],
            $others->map(fn (User $u): array => [(string) $u->name, (string) $u->email])->all(),
        );
        $this->newLine();
        $this->line(
            'The normal system has exactly one. If a second is genuinely wanted, re-run with --force. '
            .'If the existing account is unreachable, use the forgot-password screen instead — that '
            .'keeps one account that can do anything rather than two.'
        );

        return false;
    }

    /**
     * A usable e-mail address, or null after explaining what was wrong.
     */
    private function resolveEmail(): ?string
    {
        $email = mb_strtolower(trim((string) $this->option('email')));

        if ($email === '' && $this->input->isInteractive()) {
            $email = mb_strtolower(trim((string) $this->ask('Real e-mail address for the Super Admin')));
        }

        if ($email === '') {
            $this->error('--email is required. It is where the password-reset link goes, so it has to be an address somebody actually reads.');

            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('"'.$email.'" is not a valid e-mail address.');

            return null;
        }

        $domain = mb_strtolower((string) Str::afterLast($email, '@'));

        if (in_array($domain, self::UNUSABLE_DOMAINS, true) || str_starts_with($domain, 'example.')) {
            $this->error(
                '"'.$domain.'" cannot receive mail, so this account would have no way to reset its own '
                .'password. Use the real address of the person who will own the system.'
            );

            return null;
        }

        return $email;
    }

    private function resolveName(): ?string
    {
        $name = trim((string) $this->option('name'));

        if ($name === '' && $this->input->isInteractive()) {
            $name = trim((string) $this->ask('Real name of the person who will own the account'));
        }

        if ($name === '') {
            $this->error('--name is required. Every audit row this account writes is attributed to it, and "Admin" tells a later reader nothing.');

            return null;
        }

        return mb_substr($name, 0, 255);
    }
}
