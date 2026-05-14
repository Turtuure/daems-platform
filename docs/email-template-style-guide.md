# Email Template Style Guide

All Communications module HTML templates follow these conventions to keep rendering consistent across Gmail, Apple Mail, Outlook 365, Outlook 2007-19, and mobile clients.

## Layout

- Outermost: `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">`
- 600 px content-width inner table; mobile fallback via `@media (max-width:600px)` only
- No flex, grid, `position:absolute`, JavaScript, or background images
- 2-column blocks: `<table>` with two `<td width="50%">`; mobile rule sets `display:block; width:100%`

## CSS

- Inline CSS only — no `<style>` blocks except media queries
- Use the brand variables: `{{brand_primary_color}}`, `{{brand_logo_url}}`, `{{brand_footer_address}}`

## Images

- `<img width="..." style="display:block;border:0;outline:none;">` — Outlook's spacing bug

## Buttons

- `<table>` with one row, inline-styled `<td>` (mailto-compatible)
- Use `{{brand_primary_color}}` for the background colour

## Placeholders

- `{{snake_case_var}}` — `VarSubstituter` HTML-escapes values before substitution
- Whitelist per `MailKind` enforced via `MailKind::allowedVars()`

## Compatibility

- Snapshot tests in `tests/Unit/Renderer/EmailHtmlSnapshotTest.php` lock the layout per template
- Manual smoke matrix in Wave H walks Gmail / Apple Mail / Outlook 365 once per release
