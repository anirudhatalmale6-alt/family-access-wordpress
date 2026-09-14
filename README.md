# Family Access

A private members area for a family and friends WordPress site, built to stay out of
Elementor's way. Every page it touches is an ordinary page you can rebuild in the builder.

## What it does

- **Per-user page access.** Twelve numbered pages, `Page-01` … `Page-12`. The admin ticks
  which ones each member may open.
- **A menu page** where allowed tiles are solid and clickable and the rest are greyed out
  and completely inert.
- **The tick boxes are the real gate.** Typing the URL of a page you are not allowed sends
  you back to the menu with an explanation. The page title is also kept out of the site
  search and the navigation.
- **Registration** with the usual fields and a not-a-robot check — a tick box and a small
  sum out of the box, Google reCAPTCHA v2 if you would rather.
- **Approval queue.** A new sign-up cannot log in until the owner approves it, and starts
  with nothing unlocked.
- **Sessions that end when you leave.** Closing the tab or going to another site signs the
  member out; so does going idle; so does closing the browser. Moving between pages of the
  site does not.
- **Returning members are remembered, never auto-signed-in.** The username is pre-filled
  and they are greeted by name; the password is always asked for.

## Install

Plugins → Add New → Upload Plugin → `family-access.zip` → Install → Activate.

Activation creates the Login, Register, Members Menu, About and Contact pages, the twelve
numbered pages, and the Family Member role. Nothing existing is overwritten.

## Shortcodes

| Shortcode | Attributes |
|---|---|
| `[family_menu]` | `columns` (1–6, default 4), `title` |
| `[family_login]` | `title`, `button` |
| `[family_register]` | `title`, `button` |
| `[family_logout]` | `text`, `class` |
| `[family_greeting]` | `before`, `after` |

## Admin screens

**Family Access →**

- **Member Access** — the grid: every member against the twelve pages, plus their status.
- **Numbered Pages** — which real page each number points at, and the tile labels.
- **Settings** — the three key pages, session rules, the robot check, notification email.
- **How to use this** — the full instructions, in the dashboard.

The same tick boxes also appear on each member's normal WordPress profile page.

## How it is put together

```
family-access.php              bootstrap, asset loading
includes/
  class-ffac-settings.php      one option, defaults merged in
  class-ffac-pages.php         slot 1-12 -> page ID register
  class-ffac-access.php        permissions + the template_redirect guard
  class-ffac-session.php       leave / idle / session-cookie rules
  class-ffac-auth.php          front-end sign in and out, lockout
  class-ffac-registration.php  the form, the robot checks, the approval queue
  class-ffac-shortcodes.php    everything the visitor sees
  class-ffac-admin.php         the four admin screens
  class-ffac-install.php       first-run setup
assets/                        front-end CSS, session watchdog JS, admin CSS
views/help.php                 the in-dashboard instructions
```

Permissions are stored as a list of slot numbers in user meta, and the slot → page map is
a single option. That is why redesigning or even replacing a page never disturbs who can
see it.

## Requirements

WordPress 6.0+, PHP 7.4+. No other plugin needed. Tested against WordPress 7.1 with
Elementor active.

## Tests

`test_flow.py` and `test_session.py` in the project root drive a real browser through
the whole thing — 47 checks covering the guard, the greyed tiles, the robot check, the
approval queue, the idle timeout and the leave-the-site logout.
