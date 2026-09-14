# Family Access — instructions for the site owner

Everything in here is about **who gets to see what**. The look of the site stays
entirely yours: every page is a normal WordPress page you open in Elementor.

The same instructions are inside the dashboard under **Family Access → How to use this**,
so they cannot get lost.

---

## Installing it

1. WordPress admin → **Plugins → Add New → Upload Plugin**.
2. Choose `family-access.zip`, install, activate.

On activation it creates, if they are not there already:

- **Login**, **Register**, **Members Menu**, **About** and **Contact** pages
- **Page-01** to **Page-12**
- the **Family Member** role

Nothing is overwritten. If you already have a page called Login it is used as it is.

---

## 1. Letting a new person in

1. They fill in the **Register** page.
2. You get an email, and a number appears next to **Family Access** in the dashboard menu.
3. Open **Family Access → Member Access**. They are listed with the status **Waiting**.
4. Tick the numbered pages they may open, set the status to **Approved**, press **Save access**.

Until you do that they cannot sign in at all — the password is not even the point,
the account is simply switched off. Once approved they see only what you ticked.

## 2. Changing what somebody can see

Same screen: tick or untick, press **Save access**. It takes effect at once. If they are
signed in at that moment the tile greys out the next time their menu page loads, and the
page itself stops opening straight away.

The **tick all** link at the end of a row turns that whole row on or off in one go.

## 3. Turning somebody off

Set their status to **Blocked**. They keep their account but cannot sign in. This is
almost always better than deleting them, because deleting loses their details for good.

## 4. The twelve numbered pages

**Family Access → Numbered Pages** is where `Page-01` … `Page-12` are pointed at real pages.

- To redesign one, open it in Elementor and build it. Nothing here needs changing.
- To use a *different* page for a number, pick it from the dropdown and save. Permissions
  follow the number, not the page, so nobody has to be re-ticked.
- **Label on the tile** is the small line under the number on the menu. Leave it blank and
  the page's own title is used; if that is just "Page-04" the second line is left off.
- **Create any missing pages for me** makes a blank published page for every empty slot.
  Safe to press more than once.

## 5. The menu page

Where members land after signing in. It holds `[family_menu]`, which draws the twelve tiles:
allowed pages solid and clickable, everything else greyed out, dashed, and completely dead
to a click.

Shortcodes you can drop anywhere in Elementor with a **Shortcode** widget:

| Shortcode | What it does |
|---|---|
| `[family_menu]` | the twelve tiles. `columns="3"` for a different grid (1–6) |
| `[family_login]` | the sign-in form |
| `[family_register]` | the sign-up form with the not-a-robot check |
| `[family_logout]` | a sign-out button. `text="Log out"` to change the wording |
| `[family_greeting]` | "Welcome, Jane" |

## 6. Signing out

Three separate rules, all under **Family Access → Settings**:

- **Leaving the site** — closing the tab or going to another website ends the session.
  Clicking between pages of *this* site does not.
- **Going idle** — no activity for the number of minutes you set (20 by default).
- **Closing the browser** — the sign-in is a session cookie, so it never survives a restart.

A returning member is greeted by name and their username is filled in for them, but they
are **never** signed in automatically. The password is always asked for.

## 7. The not-a-robot check

Out of the box the register page uses a tick box plus a one-line sum. No keys, no account
with anybody, nothing to expire.

To use Google reCAPTCHA v2 instead:

1. Get a v2 "I'm not a robot" **site key** and **secret key** from Google.
2. Paste both into **Settings** and switch the check to reCAPTCHA.

A hidden trap field and a minimum fill-in time run either way and catch most automated
sign-ups on their own.

## 8. Usernames

WordPress, left alone, publishes the list of usernames on your site at
`/wp-json/wp/v2/users` and at `/?author=1`. Anyone can read it. That hands an attacker
half of every login before they start guessing.

The **Usernames** tick box in Settings (on by default) closes both of those, hides the
author archive pages, and stops the WordPress login screen confirming whether a username
exists. Turn it off only if something you rely on stops working.

If your administrator account is literally called `admin`, change it. The safest way is to
create a second administrator with a different name, log in as that one, and delete the
old `admin` account, assigning its content to the new one.

## 9. Things worth knowing

- Administrators and editors always see all twelve pages. You cannot lock yourself out.
- Members cannot reach the WordPress dashboard; if they try they are sent back to their menu.
- A page a member may not open is also kept out of the site search, the site navigation
  and the XML sitemap, so the titles never leak. The member pages also carry a `noindex`
  tag, so they stay out of Google even if somebody shares a link.
- Five wrong passwords pauses that username for fifteen minutes. "Waiting for approval"
  does not count as a wrong password.
- Deleting one of the twelve pages empties its slot rather than quietly pointing at
  whatever page takes over the ID.

## 10. If something looks wrong

| What you see | What it usually is |
|---|---|
| Every tile is greyed out | Nothing ticked for that member yet, or the slots are not pointed at pages under **Numbered Pages** |
| A member lands on the home page after signing in | **Menu page** is not set under **Settings** |
| The tiles do not appear at all | The `[family_menu]` shortcode is missing from the menu page, or it is in an Elementor widget that escapes shortcodes — use the **Shortcode** widget |
| Nobody gets the "new member" email | Bluehost mail is not configured. An SMTP plugin fixes it; the approval queue in the dashboard works regardless |
