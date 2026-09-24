<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Accessibility assertions over rendered HTML (phase-24-25 §6.5).
 *
 * **These are the checks a machine can actually make.** Whether a page is usable with a screen
 * reader is not decidable from its markup; whether every input has a name, whether the headings
 * descend in order, whether an icon-only button says what it does — those are, and they are also
 * the ones that break silently when somebody adds a field in a hurry.
 *
 * Each method takes rendered HTML and fails with the offending element, not with a count. "3 inputs
 * are unlabelled" sends somebody hunting; `<input name="branch_id"> at line 214` does not.
 *
 * `DOMDocument` over a regex, because HTML is not a regular language and an `aria-label` inside a
 * comment or a `<template>` would otherwise count. Parse warnings are suppressed: Blade output is
 * HTML5, `libxml` is an HTML4 parser, and it complains about `<main>` and every `x-`, `:` and `@`
 * attribute Alpine leaves behind — none of which is a problem with the page.
 */
final class A11y
{
    /**
     * Elements that may legitimately have no accessible name because they are decorative.
     */
    private const DECORATIVE_ROLES = ['presentation', 'none'];

    /*
    |--------------------------------------------------------------------------
    | Document
    |--------------------------------------------------------------------------
    */

    /**
     * Exactly one `<h1>`.
     *
     * More than one leaves a screen-reader user with no single answer to "what page is this"; none
     * leaves them with no answer at all.
     */
    public static function assertSingleH1(string $html, string $context = ''): void
    {
        $count = self::query($html, '//h1')->length;

        if ($count !== 1) {
            Assert::fail(self::message($context, sprintf('expected exactly one <h1>, found %d', $count)));
        }

        self::pass();
    }

    /**
     * Headings descend without skipping a level.
     *
     * An `<h2>` followed by an `<h4>` is a missing rung: somebody navigating by heading level has
     * no way to tell whether they have jumped a section or landed in a sub-section.
     */
    public static function assertHeadingOrder(string $html, string $context = ''): void
    {
        $previous = 0;

        foreach (self::query($html, '//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $level = (int) substr($heading->nodeName, 1);

            if ($previous !== 0 && $level > $previous + 1) {
                Assert::fail(self::message($context, sprintf(
                    'heading level jumped from h%d to h%d at "%s"',
                    $previous,
                    $level,
                    self::excerpt($heading),
                )));
            }

            $previous = $level;
        }

        self::pass();
    }

    /**
     * The page declares a language.
     */
    public static function assertHtmlLang(string $html, string $context = ''): void
    {
        $lang = self::query($html, '//html/@lang');

        if ($lang->length === 0) {
            Assert::fail(self::message($context, '<html> carries no lang attribute'));
        }

        if (trim((string) $lang->item(0)?->nodeValue) === '') {
            Assert::fail(self::message($context, '<html lang> is empty'));
        }

        self::pass();
    }

    /**
     * A non-empty `<title>`.
     */
    public static function assertUniqueTitle(string $html, string $context = ''): void
    {
        $titles = self::query($html, '//head/title');

        if ($titles->length !== 1) {
            Assert::fail(self::message($context, sprintf(
                'expected exactly one <title>, found %d',
                $titles->length,
            )));
        }

        if (trim((string) $titles->item(0)?->textContent) === '') {
            Assert::fail(self::message($context, '<title> is empty'));
        }

        self::pass();
    }

    /**
     * The landmark elements a page needs, and exactly one `<main>`.
     *
     * Landmarks are how somebody skips the navigation without reading it. Two `<main>` elements are
     * worse than none: "skip to the main content" then has two destinations.
     */
    public static function assertLandmarks(string $html, string $context = '', bool $requireNav = true): void
    {
        $main = self::query($html, '//main | //*[@role="main"]');

        if ($main->length !== 1) {
            Assert::fail(self::message($context, sprintf(
                'expected exactly one <main>, found %d',
                $main->length,
            )));
        }

        foreach (['header' => '//header | //*[@role="banner"]', 'footer' => '//footer | //*[@role="contentinfo"]'] as $name => $xpath) {
            if (self::query($html, $xpath)->length === 0) {
                Assert::fail(self::message($context, sprintf('no <%s> landmark', $name)));
            }
        }

        if ($requireNav && self::query($html, '//nav | //*[@role="navigation"]')->length === 0) {
            Assert::fail(self::message($context, 'no <nav> landmark'));
        }

        self::pass();
    }

    /**
     * A skip link, pointing at something that exists.
     *
     * A "skip to content" link whose target is not on the page is worse than no link: it takes
     * focus nowhere and the next Tab starts again from the top.
     */
    public static function assertSkipLink(string $html, string $context = ''): void
    {
        $links = self::query($html, '//a[starts-with(@href, "#")]');

        foreach ($links as $link) {
            $target = ltrim((string) $link->getAttribute('href'), '#');

            if ($target === '') {
                continue;
            }

            $text = mb_strtolower(trim($link->textContent));

            if (! str_contains($text, 'skip')) {
                continue;
            }

            if (self::query($html, sprintf('//*[@id="%s"]', $target))->length === 0) {
                Assert::fail(self::message(
                    $context,
                    sprintf('the skip link points at #%s, which is not on the page', $target),
                ));
            }

            self::pass();

            return;
        }

        Assert::fail(self::message($context, 'no skip link'));
    }

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    */

    /**
     * Every form control has an accessible name.
     *
     * Four ways count, and they are the four the specification recognises: a `label[for]`, a
     * wrapping `<label>`, `aria-label`, or `aria-labelledby` pointing at something real.
     */
    public static function assertEveryInputLabelled(string $html, string $context = ''): void
    {
        $document = self::parse($html);
        $xpath = new DOMXPath($document);

        $controls = $xpath->query(
            '//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="reset")]'
            .' | //select | //textarea'
        );

        $unlabelled = [];

        foreach ($controls ?: [] as $control) {
            if (! $control instanceof DOMElement || self::isDecorative($control)) {
                continue;
            }

            if (self::hasAccessibleName($control, $xpath)) {
                continue;
            }

            $unlabelled[] = self::excerpt($control);
        }

        if ($unlabelled !== []) {
            Assert::fail(self::message($context, sprintf(
                "%d form control(s) have no accessible name:\n  %s",
                count($unlabelled),
                implode("\n  ", $unlabelled),
            )));
        }

        self::pass();
    }

    /**
     * A button or link with no text says what it does.
     *
     * An icon-only button is announced as "button" and nothing else — so the delete icon and the
     * edit icon are the same control to anybody not looking at them.
     */
    public static function assertIconButtonsLabelled(string $html, string $context = ''): void
    {
        $document = self::parse($html);
        $xpath = new DOMXPath($document);

        $unnamed = [];

        foreach ($xpath->query('//button | //a[@href]') ?: [] as $element) {
            if (! $element instanceof DOMElement || self::isDecorative($element)) {
                continue;
            }

            if (trim($element->textContent) !== '') {
                continue;
            }

            // An <img alt> or an <svg><title> inside counts as the name.
            if (self::hasAccessibleName($element, $xpath)
                || $element->getAttribute('title') !== ''
                || $xpath->query('.//img[@alt and string-length(normalize-space(@alt)) > 0]', $element)?->length
                || $xpath->query('.//*[local-name()="title"]', $element)?->length) {
                continue;
            }

            $unnamed[] = self::excerpt($element);
        }

        if ($unnamed !== []) {
            Assert::fail(self::message($context, sprintf(
                "%d icon-only control(s) have no accessible name:\n  %s",
                count($unnamed),
                implode("\n  ", $unnamed),
            )));
        }

        self::pass();
    }

    /**
     * An invalid field points at its message.
     *
     * `aria-invalid` says something is wrong; `aria-describedby` says what. Without the second, a
     * screen-reader user is told the field is invalid and not why, which is the same as not being
     * told.
     */
    public static function assertErrorsAssociated(string $html, string $context = ''): void
    {
        $document = self::parse($html);
        $xpath = new DOMXPath($document);

        $orphans = [];

        foreach ($xpath->query('//*[@aria-invalid="true"]') ?: [] as $field) {
            if (! $field instanceof DOMElement) {
                continue;
            }

            $describedBy = trim($field->getAttribute('aria-describedby'));

            if ($describedBy === '') {
                $orphans[] = self::excerpt($field).' — aria-invalid with no aria-describedby';

                continue;
            }

            foreach (preg_split('/\s+/', $describedBy) ?: [] as $id) {
                if ($id !== '' && ($xpath->query(sprintf('//*[@id="%s"]', $id))?->length ?? 0) === 0) {
                    $orphans[] = self::excerpt($field).' — aria-describedby points at #'.$id.', which is not on the page';
                }
            }
        }

        if ($orphans !== []) {
            Assert::fail(self::message($context, implode("\n  ", $orphans)));
        }

        self::pass();
    }

    /*
    |--------------------------------------------------------------------------
    | Tables, charts and live regions
    |--------------------------------------------------------------------------
    */

    /**
     * A data table says what it is.
     *
     * A `<caption>`, an `aria-label` or an `aria-labelledby`. A layout table — one with
     * `role="presentation"` — is skipped, because it is not a table to anybody using a reader.
     */
    public static function assertTablesCaptioned(string $html, string $context = ''): void
    {
        $document = self::parse($html);
        $xpath = new DOMXPath($document);

        $unnamed = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $table instanceof DOMElement || self::isDecorative($table)) {
                continue;
            }

            $hasCaption = ($xpath->query('./caption[string-length(normalize-space(text())) > 0]', $table)?->length ?? 0) > 0;

            if ($hasCaption || self::hasAccessibleName($table, $xpath)) {
                continue;
            }

            $unnamed[] = self::excerpt($table);
        }

        if ($unnamed !== []) {
            Assert::fail(self::message($context, sprintf(
                "%d table(s) have no caption or accessible name:\n  %s",
                count($unnamed),
                implode("\n  ", $unnamed),
            )));
        }

        self::pass();
    }

    /**
     * Every chart has something a reader can read.
     *
     * A `<canvas>` is a picture with no content at all. `x-ui.chart` renders a `sr-only` table of
     * the same numbers for exactly this reason, so the assertion is that the table (or a described
     * summary) is there.
     */
    public static function assertChartHasTextAlternative(string $html, string $context = ''): void
    {
        $document = self::parse($html);
        $xpath = new DOMXPath($document);

        $bare = [];

        foreach ($xpath->query('//canvas') ?: [] as $canvas) {
            if (! $canvas instanceof DOMElement) {
                continue;
            }

            $parent = $canvas->parentNode;
            $scope = $parent instanceof DOMElement ? $parent : null;

            $hasTable = $scope !== null
                && ($xpath->query('.//table | ./following-sibling::table | ./preceding-sibling::table', $scope)?->length ?? 0) > 0;

            if ($hasTable || self::hasAccessibleName($canvas, $xpath)) {
                continue;
            }

            $bare[] = self::excerpt($canvas);
        }

        if ($bare !== []) {
            Assert::fail(self::message($context, sprintf(
                "%d chart(s) have no text alternative:\n  %s",
                count($bare),
                implode("\n  ", $bare),
            )));
        }

        self::pass();
    }

    /**
     * The regions that announce something without a page load.
     *
     * A toast is the whole feedback mechanism in this application (CLAUDE.md §6), and without
     * `aria-live` it is a message only the people who can see it receive.
     */
    public static function assertLiveRegions(string $html, string $context = '', bool $requireToast = true): void
    {
        if ($requireToast
            && self::query($html, '//*[@aria-live="polite" or @aria-live="assertive" or @role="status" or @role="alert"]')->length === 0) {
            Assert::fail(self::message(
                $context,
                'no live region — a toast nobody is told about is not feedback',
            ));
        }

        // A live region with an invalid value announces nothing, silently.
        foreach (self::query($html, '//*[@aria-live]') as $region) {
            $value = trim($region->getAttribute('aria-live'));

            if (! in_array($value, ['polite', 'assertive', 'off'], true)) {
                Assert::fail(self::message(
                    $context,
                    sprintf('aria-live="%s" is not a valid value', $value),
                ));
            }
        }

        self::pass();
    }

    /**
     * No positive `tabindex`.
     *
     * A positive value pulls an element out of document order and in front of everything with 0,
     * so one `tabindex="1"` reorders the whole page for keyboard users — usually not the way the
     * author expected, and never the way the next author expects.
     */
    public static function assertNoPositiveTabIndex(string $html, string $context = ''): void
    {
        $offenders = [];

        foreach (self::query($html, '//*[@tabindex]') as $element) {
            if ((int) $element->getAttribute('tabindex') > 0) {
                $offenders[] = self::excerpt($element);
            }
        }

        if ($offenders !== []) {
            Assert::fail(self::message($context, sprintf(
                "%d element(s) carry a positive tabindex:\n  %s",
                count($offenders),
                implode("\n  ", $offenders),
            )));
        }

        self::pass();
    }

    /**
     * Everything above, for a page that is a full document.
     */
    public static function assertPage(string $html, string $context = ''): void
    {
        self::assertHtmlLang($html, $context);
        self::assertUniqueTitle($html, $context);
        self::assertSingleH1($html, $context);
        self::assertHeadingOrder($html, $context);
        self::assertLandmarks($html, $context);
        self::assertEveryInputLabelled($html, $context);
        self::assertIconButtonsLabelled($html, $context);
        self::assertTablesCaptioned($html, $context);
        self::assertErrorsAssociated($html, $context);
        self::assertChartHasTextAlternative($html, $context);
        self::assertNoPositiveTabIndex($html, $context);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Record that an assertion passed.
     *
     * PHPUnit counts assertions, and a test whose checks all pass without calling Assert would be
     * reported as risky. `Assert::assertTrue(true)` is the cheapest honest way to say "this one
     * held" — and it is wrapped, because outside a PHPUnit run there is no Configuration registry
     * for the assertion counter to reach and the whole point of these checks is that `a11y:scan`
     * can run them from the console.
     */
    private static function pass(): void
    {
        try {
            Assert::assertTrue(true);
        } catch (\Throwable) {
            // Not running under PHPUnit. Nothing to count.
        }
    }

    /**
     * Whether an element has a name a screen reader would announce.
     */
    private static function hasAccessibleName(DOMElement $element, DOMXPath $xpath): bool
    {
        if (trim($element->getAttribute('aria-label')) !== '') {
            return true;
        }

        $labelledBy = trim($element->getAttribute('aria-labelledby'));

        if ($labelledBy !== '') {
            foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
                if ($id !== '' && ($xpath->query(sprintf('//*[@id="%s"]', $id))?->length ?? 0) > 0) {
                    return true;
                }
            }
        }

        $id = trim($element->getAttribute('id'));

        if ($id !== '' && ($xpath->query(sprintf('//label[@for="%s"]', $id))?->length ?? 0) > 0) {
            return true;
        }

        // A wrapping <label>. Walked rather than queried, because an XPath ancestor test would not
        // distinguish a label that wraps this control from one that wraps its container.
        for ($node = $element->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node instanceof DOMElement && $node->nodeName === 'label') {
                return true;
            }
        }

        return false;
    }

    private static function isDecorative(DOMElement $element): bool
    {
        return in_array($element->getAttribute('role'), self::DECORATIVE_ROLES, true)
            || $element->getAttribute('aria-hidden') === 'true';
    }

    /**
     * @return DOMNodeList<DOMElement>
     */
    private static function query(string $html, string $xpath): DOMNodeList
    {
        /** @var DOMNodeList<DOMElement> $result */
        $result = (new DOMXPath(self::parse($html)))->query($xpath) ?: new DOMNodeList;

        return $result;
    }

    /**
     * Parse HTML without libxml's HTML4 complaints.
     *
     * Blade emits HTML5 with Alpine's `x-`, `:` and `@` attributes on it; libxml knows neither, and
     * would otherwise fill the error log with warnings about a page that is perfectly fine.
     */
    private static function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * A short, recognisable rendering of an element, for a failure message.
     */
    private static function excerpt(DOMElement $element): string
    {
        $attributes = [];

        foreach (['id', 'name', 'type', 'class', 'href'] as $attribute) {
            $value = $element->getAttribute($attribute);

            if ($value !== '') {
                $attributes[] = sprintf('%s="%s"', $attribute, mb_substr($value, 0, 40));
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', $element->textContent) ?? '');

        return sprintf(
            '<%s%s>%s',
            $element->nodeName,
            $attributes === [] ? '' : ' '.implode(' ', $attributes),
            $text === '' ? '' : ' '.mb_substr($text, 0, 40),
        );
    }

    private static function message(string $context, string $detail): string
    {
        return $context === '' ? $detail : sprintf('[%s] %s', $context, $detail);
    }
}
