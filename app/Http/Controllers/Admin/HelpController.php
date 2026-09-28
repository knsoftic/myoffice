<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The operating guide, rendered in the panel it describes (`docs/ADMIN-GUIDE.md`).
 *
 * **Why it is gated on `dashboard.view` rather than a module of its own.** A manual that some roles
 * cannot open is not a manual. A new module would need its permission seeding onto every role that
 * should read it — and the roles that most need this page are the front-desk ones, which are exactly
 * the roles a new permission would miss until somebody remembered to grant it. `dashboard.view` is
 * held by every seeded admin role, is core (so it cannot be switched off), and means "may see the
 * admin panel at all", which is the right bar for a page that explains the admin panel.
 *
 * **One fixed path, no parameter.** The route takes nothing and this class reads one constant path.
 * A `?doc=` parameter over `docs/` would be a traversal question to answer and a private-runbook
 * question after it — `docs/` also holds `AAPANEL.md` and `PRODUCTION.md`, which carry server
 * details. If more guides are ever rendered, they get an allowlist here, not a parameter.
 *
 * **The output is sanitised even though the input is ours.** `Str::markdown()` on a repo file cannot
 * produce a script, so this is not defence against the file. It is decision **D25**: the system has
 * exactly one HTML sanitiser and every raw echo passes through it, because an exception that is safe
 * today is an exception somebody extends tomorrow. The `material` profile is the one that keeps
 * `code` and `pre`, which a guide full of settings keys and commands needs.
 */
final class HelpController extends Controller
{
    /** The one document this screen renders. Not a parameter, deliberately. */
    private const GUIDE = 'docs/ADMIN-GUIDE.md';

    private const CACHE_KEY = 'help.guide.html';

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('dashboard.view') ?? false, 403);

        $path = base_path(self::GUIDE);

        if (! File::exists($path)) {
            // Said plainly rather than rendered as an empty page: a blank manual reads like a manual
            // with nothing in it, which is a different and much more confusing problem.
            return view('admin.help.index', [
                'html' => null,
                'sections' => [],
                'missing' => self::GUIDE,
                'updatedAt' => null,
            ]);
        }

        /*
        | Keyed on the file's modification time, so editing the guide publishes it.
        |
        | Without the mtime in the key the cache would have to be cleared by hand after every edit,
        | which is the kind of step that gets skipped and then blamed on the screen.
        */
        $stamp = (string) File::lastModified($path);

        $rendered = Cache::remember(
            self::CACHE_KEY.':'.$stamp,
            now()->addDay(),
            fn (): array => $this->indexed(
                RichText::sanitize(Str::markdown(File::get($path)), 'material'),
            ),
        );

        return view('admin.help.index', [
            'html' => $rendered['html'],
            'sections' => $rendered['sections'],
            'missing' => null,
            'updatedAt' => File::lastModified($path),
        ]);
    }

    /**
     * Give every heading an id and list them, in one pass.
     *
     * One pass because two would drift: an index built separately from the anchors it points at is
     * an index whose links stop working the first time a heading is renamed.
     *
     * Read off the rendered HTML rather than the markdown source, so the contents cannot list a
     * heading the page does not show. `h2` is a part, `h3` a section under it.
     *
     * **The id is added after sanitising, and that is deliberate.** `id` is not an allowed attribute
     * in either profile, so one added before would simply be stripped. What is injected is
     * `Str::slug()` output -- `[a-z0-9-]+` by construction, with no way to close the attribute or
     * open a tag -- so this cannot reintroduce anything the sanitiser removed. It is the one
     * difference between what the sanitiser returned and what the page echoes, and it is named in
     * the raw-output allowlist row for this view.
     *
     * @return array{html: string, sections: list<array{level: int, id: string, title: string}>}
     */
    private function indexed(string $html): array
    {
        $sections = [];
        $seen = [];

        $html = (string) preg_replace_callback(
            '/<(h2|h3)\b([^>]*)>(.*?)<\/\1>/is',
            static function (array $m) use (&$sections, &$seen): string {
                $title = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($title === '') {
                    return $m[0];
                }

                $base = Str::slug($title) ?: 'section';

                // Two headings can slug the same; an anchor that lands on the wrong one is worse
                // than an ugly anchor.
                $id = $base;
                $n = 1;

                while (isset($seen[$id])) {
                    $id = $base.'-'.(++$n);
                }

                $seen[$id] = true;

                $sections[] = [
                    'level' => $m[1] === 'h2' ? 2 : 3,
                    'id' => $id,
                    'title' => $title,
                ];

                return sprintf('<%s id="%s"%s>%s</%s>', $m[1], $id, $m[2], $m[3], $m[1]);
            },
            $html,
        );

        return ['html' => $html, 'sections' => $sections];
    }
}
