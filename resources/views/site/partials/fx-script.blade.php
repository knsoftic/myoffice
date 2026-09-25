{{--
    The scroll-effects gate (public site).

    **This exists so the effects can never hide the website.** Every entrance effect starts its
    element at `opacity: 0` and ends it visible, which is only safe if something is guaranteed to
    finish the job. Three things can stop that happening — JavaScript never runs, the bundle fails,
    or the visitor has asked for reduced motion — and in all three the page must simply be the page.

    So the CSS that hides anything is scoped to `.fx-on` on <html>, and this script is the only
    thing that sets it. No class, no hiding: a crawler, a text browser, a blocked CDN and somebody
    with vestibular disorder all get plain, complete, immediately visible content. There is no
    `<noscript>` fallback to keep in sync, because the default state *is* the fallback.

    It runs inline in <head>, before the stylesheet, for the same reason the theme script does: a
    class decided after first paint would show the finished page and then hide it again, which is
    worse than either state on its own.

    `prefers-reduced-motion` is read here rather than only in JavaScript because this is the
    decision point — the media query is not a hint to soften the animation, it is a request not to
    animate, and the honest answer is to ship the static page.
--}}

<script nonce="{{ csp_nonce() }}">
    (function () {
        try {
            var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // IntersectionObserver is what reveals an element once it is in view. Without it
            // nothing would ever be un-hidden, so the effects stay off rather than risk a blank page.
            var supported = 'IntersectionObserver' in window;

            if (!reduced && supported) {
                document.documentElement.classList.add('fx-on');
            }
        } catch (e) {
            /* Anything unexpected: leave the effects off. The page is the priority. */
        }
    })();
</script>
