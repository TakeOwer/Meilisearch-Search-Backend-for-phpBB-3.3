# Changelog

All notable changes from 1.9.3 onwards. Versions are cumulative: installing the
latest package is enough, intermediate releases do not need to be applied in
order.

Migrations run automatically on enable and are all additive. The Meilisearch
index itself is never touched by an update, so no reindex is required unless a
release says otherwise.

---

## 1.14.0

### Added

- **Readable highlighting.** phpBB marks matched words with `.posthilit`, which
  prosilver paints a muted pink (`#F3BFCC` on `#BC2A4D`). It is hard to pick out
  in a body of text, and styles derived from prosilver often soften it further
  until the highlighting is effectively invisible. On the search pages the
  extension now replaces it with a pale yellow — the colour readers already
  associate with "this is what you searched for" — with a thin amber border and
  a dark-scheme variant.

  Controlled by **Search settings → Make highlighting easier to see**, on by
  default. Unlike the fuzzy-match option below, this costs nothing at all: it is
  a stylesheet rule, not a request. Turn it off if your style already highlights
  the way you want.

  Applied at the same specificity phpBB uses, so it simply comes later — no
  `!important`, no fight with the style.

### Migrations

- `m8_hilit_style` — adds `meilisearch_hilit_style`, default on.

---

## 1.13.3

Two separate fatal errors, both introduced by earlier releases in this series.
**Update immediately if you are on 1.12.0 – 1.13.2**: the second one takes down
the board's search page for visitors, not just the ACP.

### Fixed

- **`Unknown "endif" tag` on the Synonyms page.** Rewriting the preview in
  1.13.2 cut the old block at the first `<!-- ENDIF -->` encountered — which was
  the one *inside* the loop, not the closing one. An orphaned `END syn` and
  `ENDIF` were left behind, and Twig only notices when the page is opened.

- **`Object of class phpbb\request\request could not be converted to string` on
  `search.php`.** The constructor change that 1.12.0 was supposed to make never
  actually applied: the file kept five parameters while `services.yml` passed
  seven, so `@request` landed in `$root_path`.

  PHP does not complain about surplus arguments to a userland function — it
  ignores them, and the preceding parameter silently receives the wrong value.
  That is why this survived three releases without a symptom until a visitor
  opened the search page.

### Added

Both failures were mechanically detectable, so the test suite now checks for
them. Each check was verified by reintroducing the corresponding defect and
confirming the suite rejects it.

- Template `IF`/`ENDIF` and `BEGIN`/`END` tags are balanced.
- Every service receives as many arguments as its constructor accepts, by
  comparing `services.yml` against the class signature.

---

## 1.13.2

### Changed

- **The synonym preview is no longer kilometres long.** A few dozen groups
  expand into a few hundred entries, and the previous table pushed the rest of
  the page — including the save button — far below the fold. It is now a
  two-column grid in a fixed-height scrolling box, with the row under the cursor
  highlighted. Below 900px it falls back to a single column.

### Added

- **A live filter** over the preview. With a few hundred entries, scrolling to
  check whether a particular pairing exists is not practical; typing `sub` now
  narrows the list to `subtitles`, `sub`, `subs`, `softsub` and `hardsub` at
  once. The filter matches both the term and its synonyms, against a haystack
  prepared server-side rather than re-reading rendered text on every keystroke.

---

## 1.13.1

### Changed

- **Starter lists are discovered by scanning the folder**, the way phpBB scans
  `language/` rather than holding a list of locales in code. Dropping
  `synonyms_fr.txt` into the extension's `data/` folder is enough for its
  buttons to appear — no PHP to edit. The display name comes from an optional
  `# name: Français` header in the file, so a language the extension has never
  heard of still gets a readable label instead of a bare code.

### Fixed

Both found by tests written for the scanning feature, both introduced by it.

- `read_starter()` still forced the language to `it` or `en`, so the buttons for
  any other language would have appeared and then loaded the English list.
- `clean_code()` stripped disallowed characters instead of rejecting the value,
  turning `../../etc/passwd` into `etcpasswd` — harmless, since no slashes
  survive, but silently substituting one value for another. A malformed code is
  now refused outright.

---

## 1.13.0

### Added

- **Starter synonym lists**, one Italian and one English, shipped in `data/`.
  They cover language names, video and audio formats, content types, common
  computing terms and the phrases that recur on any board — 42 and 39 groups
  respectively.

  Four buttons on the Synonyms page: add or replace, per language. Adding merges
  in only the groups you do not already have, comparing groups on their sorted
  terms so the same group written in a different order is not duplicated.
  Replacing asks for confirmation.

  **Loading fills the box; nothing is saved until you press Save and apply**, so
  the list can be reviewed and edited first.

Each list opens with a note explaining why antonyms do not belong in it:
declaring `large, small` as a group means someone searching for "large screen"
gets the posts about small ones. Synonyms close the gap between how a thing is
written and how it is searched for; antonyms widen it.

### Note on where things live

The starter files sit inside the extension and are **replaced on every update**,
which is deliberate: corrections arrive with the code. Editing them is possible
but pointless, because the usual update procedure deletes the extension folder.
The administrator's own list is held in `config_text`, in the database, and
survives updates untouched.

---

## 1.12.0

### Added

- **Highlighting of fuzzy matches.** phpBB highlights the words the user typed.
  Meilisearch also returns posts matched through a typo, a synonym or a
  different inflection, and those stay unmarked — so a result appears with
  nothing highlighted and no visible reason why it matched. The extension now
  asks Meilisearch which words it actually matched on the page being shown and
  adds them to phpBB's own highlight list.

  Controlled by **Search settings → Highlight fuzzy matches**, **off by
  default**: it costs one extra request per results page, and on a Meilisearch
  instance reached over the network that is a round trip the visitor waits for.
  Check the round-trip time on the diagnostics page before enabling it.

### Implementation note

This works on **every style**, prosilver-derived or not, because it does not
touch a template. phpBB exposes `core.search_modify_rowset` with the `$hilit`
string by reference, so the extension appends words to the list phpBB already
highlights and the core's own tag-aware regex does the work — on both the
subject and the body.

The obvious alternative, rewriting the message text and wrapping matches
ourselves, would mean parsing the HTML that is already in the excerpt, with the
risk of highlighting inside an `href`.

If the extra request fails, highlighting simply does not happen: it is
decoration and must never disturb an otherwise good results page.

### Migrations

- `m7_highlight` — adds `meilisearch_highlight`, default off.

---

## 1.11.0

### Added

- **Synonyms, with their own ACP page.** One group per line, terms separated by
  commas; every term in a group finds every other term, **in both directions**.
  Meilisearch stores synonyms one way round, so a group of three produces three
  entries — declaring only one direction is the classic mistake and would mean
  `sub` finds `subtitles` but not the reverse.

  The page shows the map actually sent to Meilisearch, so a missing pairing is
  visible before it is a mystery on the board. Skipped lines are reported with
  their line number rather than silently dropped. Saving writes the list and
  applies it to the index in the same step: a list saved but never sent would do
  nothing at all, which is worse than refusing to save.

  Synonyms are applied at query time, so **no reindex is needed**.

- **Attachment file names and captions are indexed.** Neither phpBB's native
  backend nor this one used to index them, which on a board where files are
  shared is probably the most common search that silently returned nothing.

  **This one does require a reindex**: the field is written by the indexer, so
  existing documents do not have it. Use the reindex panel on the Indexed forums
  page; it replaces documents in place, so search keeps working while it runs.

### Fixed

- `mb_strlen` was called without the `function_exists` guard its neighbour
  `mb_strtolower` had, which would have been a fatal error on a server without
  mbstring. Found by the tests; the fallback is now centralised.

### Migrations

- `m6_synonyms` — registers the Synonyms ACP page.

---

## 1.10.0

### Added

- **A test suite**, run with `php tests/run.php` from the extension root. No
  phpBB installation required; exits non-zero on failure, so it fits in a CI
  step.

  Two kinds of check. The query builder is exercised directly — filters, integer
  coercion, AND composition, limits, locales, the guest-author case. And the
  package is checked statically for language keys used but never defined,
  strings carrying sprintf placeholders rendered as `{L_...}` in a template,
  template variables nobody assigns, English and Italian drifting apart, class
  names not matching their path, and log strings sitting outside `logs.php`.

  Every one of those static checks catches a defect that had actually shipped.

- **The visibility filter is pushed down to Meilisearch** when the user holds
  `m_approve` in no forum at all — the only case where phpBB's visibility rule
  reduces to "approved". This is about accuracy, not security: the candidate cap
  is finite, and without it unapproved and soft-deleted posts consume slots that
  SQL then discards, so a broad query reports fewer results than exist.

  Users who moderate somewhere get no pushdown and SQL decides alone, exactly as
  before. **The two-stage model is unchanged**: phpBB's own SQL remains the last
  word on what a user may read.

### Changed

- The Meilisearch query payload is built by a new `meili\query_builder`, a pure
  function of its arguments. It was inline in the backend and therefore not
  verifiable without a phpBB installation.

---

## 1.9.3

### Added

- The extension version is shown as a small chip at the end of the front-end
  search notice, with the full name on hover. Read from `composer.json` rather
  than a constant, so the number shown to visitors cannot drift from what was
  actually shipped.

  Note that the notice is visible to everyone, guests included: publishing a
  version number tells anyone which code the board runs. The risk is modest, but
  worth knowing. The notice itself remains optional.

---

## Upgrading

1. **Disable** the extension in the ACP first — never delete the folder while it
   is enabled, and never press *Delete data*.
2. Delete `ext/salvocortesiano/meilisearch/` and upload the new folder.
3. **Enable** the extension. Any missing migrations run here.
4. Purge the board cache.
5. If coming from below 1.11.0, reindex from **Indexed forums** so attachment
   names enter existing documents. Search keeps working while it runs.

Nothing is lost: the Meilisearch index lives on the Meilisearch instance, and
every setting — forum exclusions, URL, API key, locales, relevance mode, synonym
list — is held in the database, which *Disable* does not touch.
