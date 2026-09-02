# Release Notes for Translingua

## 1.0.4 - 2026-09-02

### Changed

- Renamed the free edition from **Lite** to **Standard**, handle `lite` → `standard`. The Plugin Store matches editions by handle against the Craft Console listing, and the listing calls the free edition Standard — so nothing ever matched: the store kept offering **Install** for an edition that was already installed, and pressing it posted a `switch-edition` for a handle `editions()` doesn't define, which is the request that leaves the store spinning. The paid edition keeps the handle `plus`.
- Installs carrying the old `lite` handle in project config resolve to `standard` on their own — same edition, same features, nothing to migrate. A `plus` install is untouched.

## 1.0.3 - 2026-09-02

### Fixed

- Installing Translingua from the Plugin Store installed the Plus edition when Lite was picked. `editions()` listed Plus first, and Craft reads that list as least to most feature-rich — so Plus was both the fallback a fresh install landed on and the edition Craft considered the top of the range. The list is back in the right order.
- Switching back down to Lite left the Plugin Store spinning forever. Same cause: with the order inverted, the switch read as an upgrade, so it never came back as a plain edition change. Switching now completes and reports what it did.
- The **Test connection** button reported “Connected” for a provider that answered but failed the check, contradicting the banner at the top of the same screen. It now reports what actually happened, and makes one round trip instead of two.
- A slow or unreachable provider could leave the settings screen loading for two minutes. Checks an admin is waiting on now use a short timeout and say the provider couldn’t be reached, instead of surfacing a raw cURL error.

### Changed

- Switching editions clears anything remembered about the previous one, so a Lite install can’t inherit a “connection verified” flag and an upgrade re-proves the connection rather than trusting a stale result.
- Opening the AI translations screen on Lite now explains why and lands on the settings screen, instead of a bare 403. Requests expecting JSON still get the status code.
- The settings screen names the active edition handle, shows the command for switching in the other direction, and marks the AI section as inactive on Lite instead of offering a **Test connection** button that can only fail.
- Removed `translingua/ai/new`, a route that pointed at an action that was never written.

## 1.0.0 - 2026-08-28

Initial release.

### Added

#### Static translations (Lite)

- Static translation editor for template strings, scanned from `|t`, `Craft::t()` and `Craft.t()` calls across templates, modules and local plugins.
- Language file overrides for any installed plugin that ships translations, plus Craft's own control panel strings. Strings a plugin already translates are shown pre-filled and marked as defaults; editing one turns it into a project override.
- Formie translations, editable from the control panel.
- Add, edit and delete individual strings, with search, a missing/translated filter and pagination for large sets. Strings carrying meaningful leading or trailing whitespace are outlined.
- Scan results are cached against the modification times of the files they were read from, so an unchanged project never pays for a rescan. A **Rescan templates** button forces one.
- Everything is written to `translations/{locale}/{category}.php` — `vendor/` is never modified.

#### AI translations (Pro)

- AI translations through DeepL, OpenAI, Anthropic (Claude) or Google Gemini, using your own API key. Keys support environment variables.
- A translate button on every text input in the control panel, including CKEditor, and inside other plugins' screens such as Formie and SEOmatic — the buttons read the DOM rather than knowing about specific field types.
- A **Translate page** button next to Save, limited to entry, category and global set edit screens plus SEOmatic's Global SEO and Content SEO. Configurable via the `pageButtonPaths` setting.
- Batch translation of entries, categories and global sets from one site into any number of others, on the queue, with per-field selection that reaches into Matrix blocks. Fields whose translation method is `none` are shown but disabled, since Craft stores one value for every site.
- Optionally creates a revision before each batch write, so a run can be rolled back from the element's revision menu.
- A Translingua tab in Formie's form builder: set the language the form is written in, and translate the whole form server-side. Field handles, option values, CSS classes and input attributes are never translated.
- A **Translingua** toggle on each section, category group and global set's own settings screen, governing every feature for that source.
- Identical strings are cached for a month, so re-running a batch costs nothing for anything already translated.
- Console commands for listing sources, scanning templates and running batch translations.

### Security

- Two user permissions are registered — **Manage static translations** and **Use AI translations**. Plugin settings remain admin-only.
- The translate buttons only appear once a real request to the provider has succeeded, so a wrong, expired or out-of-quota key means no buttons rather than failures at the point of use.
- URLs, paths, email addresses, phone numbers, bare numbers and Craft's own revision notes are never sent to the translation provider, whatever the field is called. Link fields pointing at an email address, phone number or SMS number are left alone entirely.
- No translate buttons are loaded at all on Settings, the Plugin Store, Utilities, Updates, user accounts or plugin configuration screens.
- Nothing is ever saved on your behalf: translations land in the input and you press Save.
