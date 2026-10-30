---
paths:
  - "templates/**/*.twig"
---

# Twig Coding Standards

Binding formatting rules for all `.twig` files.
Source: <https://twig.symfony.com/doc/3.x/coding_standards.html>.

## Spacing

- **Delimiters:** exactly one space after `{{` `{%` `{#` and before `}}` `%}` `#}`.
  With whitespace control, no space between the control char and the delimiter: `{{- x -}}`.
  ✅ `{{ user }}` `{# comment #}` — ❌ `{{user}}` `{#comment#}`
- **Operators:** one space before and after `==`, `!=`, `<`, `>`, `>=`, `<=`, `+`, `-`,
  `*`, `/`, `//`, `%`, `**`, `~`, `and`, `or`, `not`, `is`, `in`, ternary `? :`.
  ✅ `{{ a ~ ' ' ~ b }}` `{{ ok ? 1 : 0 }}` — ❌ `{{ a~' '~b }}`
- **`,` and `:` in collections:** exactly one space after, none before.
  ✅ `[1, 2, 3]` `{'name': 'Fabien'}` — ❌ `[1,2,3]` `{'name':'Fabien'}`

## No spacing

- **Parentheses:** no space after `(` / before `)`. ✅ `{{ 1 + (2 * 3) }}`
- **String delimiters:** no inner space. ✅ `{{ 'Twig' }}` — ❌ `{{ ' Twig ' }}`
- **`|` `.` `..` `[]`:** no surrounding space.
  ✅ `{{ user.name|upper }}` `{{ list[0] }}` `{% for i in 1..12 %}` — ❌ `{{ name | upper }}` `{{ user . name }}`
- **Filter/function parentheses:** no surrounding space. ✅ `{{ name|default('x') }}` `{{ range(1..10) }}`
- **Array/hash bounds:** no inner space. ✅ `[1, 2, 3]` `{'k': 'v'}` — ❌ `[ 1, 2, 3 ]`

## Named & default arguments

- **Named arguments with `:`** (not `=`), one space after the `:`.
  ✅ `{{ html_input(class: 'input') }}` — ❌ `{{ html_input(class='input') }}`
- **Macro defaults with `=`**, one space around it.
  ✅ `{% macro field(class = 'input') %}`

## Naming & indentation

- **`snake_case`** for variables, function/filter/test names, arguments, named arguments.
  ✅ `{% set first_name = 'x' %}` — ❌ `{% set firstName = 'x' %}`
- **Indent code inside tags** (same indentation as the target language, 4 spaces as in the project):

  ```twig
  {% block content %}
      {% if ready %}
          {{ value }}
      {% endif %}
  {% endblock %}
  ```

## Content conventions

- All user-facing text via `|trans` (no hardcoded strings) — `translations/messages.{de,en}.yaml`.
- Tabler icons as inline `<svg>` (never FontAwesome); routes via `path()`/`url()`.
- Accessibility: semantic tags, `aria-*`, a label on every form control.

## Form conventions

- `_form.html.twig` manages its own `card-body` wrapper + `card-footer` (Cancel/Save);
  parents include it directly inside `<div class="card">` — **without** an extra `card-body` wrapper.
- Hash literals for `path()`/`form_*` options are allowed and common
  (`{'attr': {'class': '…'}}`) — the trailing double `}}` is the hash close,
  not a broken output delimiter.
- **`RepeatedType` required:** `required` on the parent `RepeatedType` does **not** propagate to the
  children's `vars.required`. In the template check `form.plainPassword.vars.required` (parent),
  **not** `form.plainPassword.first.vars.required`.
- **Sidebar active state:** use the `app.current_route starts with 'app_user'` pattern for
  highlighting. Collapsible submenus use Bootstrap `data-bs-toggle="collapse"` with the `show`
  class when the section is active.
- **Field help is collapsible (#79) and has exactly one source.** The source is the form type's
  `help` option, read in the template as `field.vars.help` — never a translation key hard-coded
  in the template, or the two drift apart unnoticed. Render it with the macros from
  `_include/_form_macros.html.twig`:

  ```twig
  {% from '_include/_form_macros.html.twig' import help_toggle, help_text, help_switch %}
  {{ form_start(form, {'attr': {'novalidate': 'novalidate', 'data-controller': 'form-help'}}) }}
  {{ help_switch() }}   {# only in forms with two or more hints #}
  …
  <label class="form-label" for="{{ form.x.vars.id }}">
      {{ form.x.vars.label|trans }}
      {{ help_toggle(form.x.vars.id, form.x.vars.help) }}
  </label>
  {{ form_widget(form.x) }}
  {{ help_text(form.x.vars.id, form.x.vars.help) }}
  ```

  Both macros render nothing when there is no hint, so they are safe to call unconditionally.
  A hint that belongs to something which is **not** a form field (a display block, a hand-written
  `<select>`) passes an explicit id and translation key instead.

  **Never pass a `d-*` display utility to `help_text`** (e.g. `'mt-1 d-block'`): Tabler's `d-block`
  is `display: block !important` and beats the `hidden` attribute the controller collapses with — the
  hint then can never fold (the user and settings forms had exactly this until #239). `.form-hint`
  is already a block; spacing classes like `mt-1` are fine.

  **Do not add `hidden` in the template.** The markup renders hints expanded and the `form-help`
  Stimulus controller collapses them on connect, so the text stays readable without JavaScript.
  Marking it up collapsed would hide it permanently for anyone without JS.

  Help that is a *constraint the user must satisfy right now* stays visible — see
  `security/change_password.html.twig`, where the rule is an `alert-info` on the forced
  password-change screen, not a `form-hint`.

## Macros

- **Define `{% macro %}` at the template root** — outside any `{% block %}`, `<table>`, `for`/`if`,
  etc. Since Twig 3.27 a macro nested inside another tag is deprecated, and **Twig 3.28 makes
  `make twig-lint` fail hard** on it (`Using the "macro" tag outside the root of a template`).
  Call root macros from anywhere in the same file via `_self.macro_name(...)` — the call site does
  **not** have to be at the root, only the definition. (This bit the `sort_link` macros that lived
  inside `<thead>` on every index page; they were hoisted to the root on the 3.28 bump.)
- **Cross-template reuse:** extract shared macros to a partial (e.g. `_include/_form_macros.html.twig`)
  and `{% from '…' import name %}` — same root-definition rule applies there.

## Stimulus references

Every `data-controller` / `data-action` / `stimulus_controller('…')` name must have a matching
`assets/controllers/<name>_controller.js` (kebab → snake). A missing file is a silent no-op Biome
can't see — **`make lint-stimulus`** (in `make verify`, so also `make ci`) catches it. `live` and
namespaced `…--…` third-party controllers are skipped.

## Theme cookie

Dark/light mode is persisted as a `theme` cookie, read **server-side** in `base.html.twig` to set
`data-bs-theme` on `<html>` before paint. The toggle button writes a **session cookie** (no `max-age`).
The profile save in `UserSettingsController` writes a **1-year cookie** (survives browser restart).
Clearing the theme in the profile calls `clearCookie('theme')`.

## Enforcement

This rule is advisory (applies when editing `*.twig`). For mechanical checking,
[`vincentlanglet/twig-cs-fixer`](https://github.com/VincentLanglet/Twig-CS-Fixer)
enforces exactly these standards — currently **not** wired into the project.
`make twig-lint` only checks syntax + deprecations, **not** the coding style.
