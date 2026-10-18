---
name: solrengine-accessibility
description: Accessibility rules and audit for SolrEngine's front end — the search popup, the suggestion list, the filter panel, and the templates themes override. Use before and while changing anything a visitor sees or operates (assets/src, templates/, the markup a PHP class prints), when a change touches keyboard handling, focus, ARIA, colour or motion, and before calling such a change done.
---

# Accessibility in SolrEngine

The target is WCAG 2.2 AA, checked, not assumed. WordPress.org's accessibility
guidelines and the theme review's keyboard rules apply on top. `AGENTS.md` wins
over anything here; `docs/search-modal.md#accessibility` records what the popup
promises, and a change that breaks a promise there is a regression.

## The patterns, and why

**Search results are links, not a listbox.** A combobox's listbox is for
choosing a value; its options may not contain links, and a result *is* a link to
a page. So the popup's field is a plain `type="search"` field, results are
`<ul role="list">` of `<li>` with one `<a href>` each, and Tab reaches them like
any link. Do not reintroduce `role="combobox"`, `role="listbox"`,
`role="option"` or `aria-activedescendant` around results that navigate — axe
reports it as `nested-interactive`, and screen readers announce it badly.

The suggestion list (`instant-search.js`) still uses the combobox pattern:
`role="combobox"` on the field, a listbox of plain-text options, and
`aria-activedescendant`. Its options hold no links, so axe does not flag it,
but choosing one navigates (`window.location.href = suggestion.url`) instead of
filling the field, which is not what a combobox announces. It has not had the
popup's audit. Review it with the same questions before changing it, and do not
copy its pattern into new code that navigates.

**Arrow keys move real focus.** ↓ from the field focuses the first result,
arrows move between results, ↑ on the first returns to the field, Home/End jump,
and typing on a result goes on typing in the field. Arrows are a shortcut; Tab
must reach everything they reach.

**One focusable element per card.** The card's link. It is named by the title
(`aria-label`) and described by type, price, date and author
(`aria-describedby`), so a screen reader hears "Hoodie, link, Product, 42 €" and
not every word in the card. Images in a card are decorative (`alt=""`): the
link already says what it is.

**Never disable the element that has focus.** Disabling a focused button throws
focus to the top of the document. Use `aria-disabled="true"` and ignore
activation instead. When content the visitor asked for arrives (a next page),
move focus to the first new item; when it arrived by scrolling, leave focus
alone.

**Opening is never triggered by focus alone** (WCAG 3.2.1). The popup opens on
click, typing, Enter or ↓ in the theme's field — so tabbing through a header
does not throw a dialog at anyone.

**Native `<dialog>` with `showModal()`.** It makes the page inert and contains
focus without a hand-rolled trap. Escape closes; focus returns to the opener.
Escape is also handled on the field, because a search field with text eats
Escape to clear itself.

**Tabs follow the ARIA tabs pattern.** One tab in the tab order (roving
`tabindex`), arrows and Home/End move and select, and the results container is
the `tabpanel`, labelled by the selected tab.

**Announce, don't move.** Result counts, "no results", errors and "Showing 48 of
338" go to the polite live region (`data-solrengine-status`). Focus stays where
the visitor put it.

**Names include the visible text** (WCAG 2.5.3). An `aria-label` starts with
what is on screen: "See all 20: Posts" for a button that shows "See all 20".

## Visual rules

- Text contrast ≥ 4.5:1 on *every* surface it sits on — check the pills, the
  field background, hover states and the footer, not just white. Placeholder
  text and struck-through prices are text. Compute it; do not eyeball it.
- Focus is an outline, not only a colour or shadow change: 2px on controls,
  3px around a card, offset so it is not clipped. It must survive
  `forced-colors: active` (use `Highlight` there).
- Motion respects `prefers-reduced-motion`.
- Targets are at least 24×24 CSS px (WCAG 2.5.8).
- `.screen-reader-text` uses `clip` and `clip-path` both.

## Templates are public API

Themes copy `templates/`. When a pattern changes, the script should still cope
with an older copy (the popup strips `role="option"` and `tabindex="-1"` from
pre-0.11 cards) and `docs/search-modal.md` must say what changed. The template
docblocks state which elements carry meaning; keep them true.

## Checking a change

1. `npm run lint:js` and `npm run lint:css`, then `npm run build`.
2. The keyboard and axe audit, on a running site with indexed content:

   ```bash
   SOLRENGINE_A11Y_URL=http://example.test/ npm run test:a11y
   ```

   Options are documented at the top of `tests/a11y/search-modal.js`
   (`SOLRENGINE_A11Y_QUERY`, and `SOLRENGINE_A11Y_COOKIE` for a site in
   maintenance or WooCommerce "coming soon" mode — generate a logged-in cookie
   with WP-CLI and never print it). It types into the theme's real search field,
   walks Tab and the arrow keys, pages a type tab by keyboard, and runs axe-core
   (WCAG 2.0–2.2 A/AA plus best practices) on two states. Every line must PASS.
3. When you add a behaviour, add its check to that script.
4. By hand, once per change that alters structure: VoiceOver (Safari) or NVDA
   (Firefox/Chrome) — open the popup, hear the count announced, reach a result
   by Tab and by ↓, hear its name and description, close with Escape. Then
   200% zoom and a 320px-wide window: nothing clipped, nothing scrolls
   sideways.
5. `tests/Integration/SearchModalTest.php` asserts the markup contract (no
   listbox roles, the field's hint). Extend it when the contract changes.

## Report honestly

Say what was checked and how: which automated rules, which keys, which screen
reader. An axe pass is not an accessibility pass; it finds perhaps a third of
real problems. Do not claim screen-reader behaviour you did not hear.
