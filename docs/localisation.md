# English and Dutch

English is the default. The EN/NL selector is available in authenticated navigation,
on sign-in and password pages, and on anonymous timing and results pages. The
selection is saved in the session and a one-year cookie, so it also survives logout.

`lang/nl.json` is shared by Laravel and Vue. English source phrases are translation
keys: use `__('Phrase', ['name' => $name])` in PHP and `tr('Phrase', {name})` or
`$t('Phrase', {name})` in Vue. Use named placeholders for complete messages.
Laravel validation, authentication, password and pagination messages live in
`lang/nl/`. Format displayed dates and distances using the active locale.

Translate display labels, never submitted enum values, field names, checkpoint
codes, CSV import headers, or stored race configuration. Custom names fall back to
their original text. Dutch uses **solo**, **trio**, **controlepunt**, **wissel**,
**official**, **atleet**, and **organisator (admin)**. Course defaults remain
1 km swimming, 35 km cycling and 8 km running.

Language changes preserve form input and the selected timing checkpoint. Server
responses update the frontend locale before rendering; historical pages in a
different locale reload their server messages in the current language.

Run `yarn test:frontend`, `yarn typecheck`, `yarn build`, and `php artisan test`.
The localisation tests check catalogue coverage and placeholders, cookie/session
persistence, validation and emails, anonymous pages and exports. The browser suite
also checks mobile language selection, stable filter and checkpoint values, and
all three role guides: `yarn playwright test localisation.spec.ts --project=chromium-smoke`.
