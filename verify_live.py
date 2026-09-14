"""Check the live site from the outside, signed in to nothing.

Everything here is what a stranger with a browser can see, which is exactly the
thing that matters: if a private page is reachable or a username is readable
without logging in, it shows up here.

    python3 verify_live.py [https://sandlcasa.com]
"""
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "https://sandlcasa.com").rstrip("/")
UA = {"User-Agent": "Mozilla/5.0 (site check)"}
results = []


def check(name, ok, detail=""):
    results.append((name, bool(ok)))
    print(("PASS  " if ok else "FAIL  ") + name + ("  " + detail if detail else ""))


def fetch(path, follow=True):
    """Return (status, final_url, body). Never raises on an HTTP error code."""
    url = BASE + path

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    try:
        r = opener.open(urllib.request.Request(url, headers=UA), timeout=30)
        return r.getcode(), r.geturl(), r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        loc = e.headers.get("Location", "") if e.headers else ""
        return e.code, loc or url, e.read().decode("utf-8", "replace")
    except Exception as e:
        return 0, url, f"{type(e).__name__}: {e}"


print(f"Checking {BASE} as a stranger\n")

# --- the site is still standing -------------------------------------------
code, url, home = fetch("/")
check("home page still loads", code == 200, f"HTTP {code}")
if "sandlcasa" in BASE:
    # His own landing page must survive the install untouched.
    check("home page is still Steve and Lindsay's",
          "SandLCasa" in home or "invite only" in home)

# --- the new pages exist ---------------------------------------------------
for slug, needle, label in [
    ("/login/", "ffac-login", "login page"),
    ("/register/", "ffac-register", "register page"),
]:
    code, url, body = fetch(slug)
    check(f"{label} is live", code == 200 and needle in body, f"HTTP {code}")

code, url, body = fetch("/register/")
check("register page has the not-a-robot check",
      'name="ffac_human"' in body or "g-recaptcha" in body)
check("register page has the hidden bot trap", 'name="ffac_website"' in body)

# --- the private pages are private ----------------------------------------
leaked = []
for n in range(1, 13):
    code, url, body = fetch(f"/page-{n:02d}/")
    private = ("/login" in url) or ("ffac-login" in body) or ("ffac=login_required" in url)
    if not private:
        leaked.append(f"page-{n:02d} (HTTP {code})")
check("all twelve member pages refuse a stranger",
      not leaked, ", ".join(leaked) if leaked else "12/12 protected")

code, url, body = fetch("/members-menu/")
check("the menu page refuses a stranger",
      "/login" in url or "ffac-login" in body, url)

# --- search engines --------------------------------------------------------
code, url, sitemap = fetch("/wp-sitemap-posts-page-1.xml")
in_map = [f"page-{n:02d}" for n in range(1, 13) if f"/page-{n:02d}/" in sitemap]
check("member pages kept out of the sitemap",
      code == 200 and not in_map and "/members-menu/" not in sitemap,
      ", ".join(in_map) if in_map else "clean")
check("the public pages are still in the sitemap", "/about/" in sitemap or "/contact/" in sitemap)

code, url, index = fetch("/wp-sitemap.xml")
check("author pages dropped from the sitemap index", "users" not in index)

# --- usernames -------------------------------------------------------------
code, url, users = fetch("/wp-json/wp/v2/users")
check("REST users endpoint closed", code in (401, 403, 404), f"HTTP {code}")
check("no username in the response", '"slug"' not in users, users[:80])

code, url, body = fetch("/?author=1", follow=False)
check("?author=1 no longer names the admin", "/author/" not in url, url)

# --- the login form itself -------------------------------------------------
code, url, body = fetch("/login/")
check("login form does not offer 'remember me'", 'name="rememberme"' not in body)
check("login form warns about the automatic sign-out", "signed out automatically" in body)

failed = [r for r in results if not r[1]]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
