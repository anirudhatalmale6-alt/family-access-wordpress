"""Session rules and the admin approval screen, tested for real."""
import subprocess
import urllib.error
import urllib.request
import sys
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8710"
WP = "/var/lib/freelancer/projects/40710857/fam-wp"
SHOTS = "/var/lib/freelancer/projects/40710857/shots"
results = []


def check(name, condition, detail=""):
    results.append((name, bool(condition)))
    print(("PASS  " if condition else "FAIL  ") + name + ("  " + detail if detail else ""))


def php(code):
    script = f"<?php require_once '{WP}/wp-load.php'; {code}"
    open("/tmp/claude-1003/-home-freelancer/5193697f-1c78-47c7-8366-425e5da068bc/scratchpad/_t.php", "w").write(script)
    out = subprocess.run(["php", "/tmp/claude-1003/-home-freelancer/5193697f-1c78-47c7-8366-425e5da068bc/scratchpad/_t.php"],
                         capture_output=True, text=True)
    return (out.stdout + out.stderr).strip()


def member_login(page, user, password="FamilyTest!2026"):
    page.goto(f"{BASE}/login/", wait_until="domcontentloaded")
    page.fill("#ffac-login-username", user)
    page.fill("#ffac-login-password", password)
    page.click("button[type=submit]")
    page.wait_for_load_state("domcontentloaded")


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1280, "height": 800})
    page = ctx.new_page()

    # --- leaving the site ----------------------------------------------------
    member_login(page, "janet")
    check("signed in", "/members-menu/" in page.url, page.url)

    # Moving around inside the site must NOT sign anyone out.
    page.click(".ffac-tile--open")
    page.wait_for_load_state("domcontentloaded")
    page.go_back()
    page.wait_for_load_state("domcontentloaded")
    page.goto(f"{BASE}/members-menu/", wait_until="domcontentloaded")
    check("moving between pages keeps the session", "/members-menu/" in page.url, page.url)

    # Now do exactly what the browser does when the member leaves the site.
    page.evaluate("""() => {
        const body = new URLSearchParams();
        body.set('action', 'ffac_leave');
        body.set('nonce', window.ffacSession.nonce);
        return fetch(window.ffacSession.ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        }).then(r => r.status);
    }""")
    page.wait_for_timeout(4000)
    page.goto(f"{BASE}/members-menu/", wait_until="domcontentloaded")
    check("leaving the site signs the member out", "ffac=left" in page.url or "/login/" in page.url, page.url)
    check("told why they were signed out", "when you left the site" in page.content(), "")
    page.screenshot(path=f"{SHOTS}/10-left-site.png")

    # --- idle timeout --------------------------------------------------------
    member_login(page, "janet")
    check("signed in again", "/members-menu/" in page.url, page.url)
    php("$u = get_user_by('login','janet'); "
        "update_user_meta($u->ID, FFAC_Session::META_SEEN, time() - 3600); "
        "delete_user_meta($u->ID, FFAC_Session::META_LEFT); echo 'aged';")
    page.goto(f"{BASE}/page-01/", wait_until="domcontentloaded")
    check("idle session is dropped", "ffac=timeout" in page.url, page.url)
    check("idle message shown", "left idle" in page.content())
    page.screenshot(path=f"{SHOTS}/11-idle-timeout.png")

    # --- the admin approving somebody ---------------------------------------
    admin = browser.new_context(viewport={"width": 1280, "height": 800})
    apage = admin.new_page()
    apage.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    apage.fill("#user_login", "famadmin")
    apage.fill("#user_pass", "FamAdmin!2026demo")
    apage.click("#wp-submit")
    apage.wait_for_load_state("domcontentloaded")
    check("admin signed in", "/wp-admin/" in apage.url, apage.url)

    apage.goto(f"{BASE}/wp-admin/admin.php?page=ffac-members", wait_until="domcontentloaded")
    # Wait for the table rather than asserting on the instant: the first dashboard
    # load after a theme or plugin change can be slow enough to fail a bare count,
    # which is a red test rather than a real fault.
    try:
        apage.wait_for_selector("table.ffac-grid", timeout=20000)
    except Exception:
        pass
    check("member access screen loads", apage.locator("table.ffac-grid").count() == 1)
    check("the new registration is listed", "samtaylor" in apage.content())
    apage.screenshot(path=f"{SHOTS}/12-admin-members.png")

    uid = php("$u = get_user_by('login','samtaylor'); echo $u ? $u->ID : 0;")
    apage.select_option(f"select[name='ffac_user[{uid}][status]']", "approved")
    apage.check(f"input[name='ffac_user[{uid}][slots][]'][value='2']")
    apage.check(f"input[name='ffac_user[{uid}][slots][]'][value='4']")
    apage.click("button.button-primary")
    apage.wait_for_load_state("domcontentloaded")
    check("access saved", "Saved." in apage.content(), apage.url)
    apage.screenshot(path=f"{SHOTS}/13-admin-saved.png")

    stored = php(f"echo implode(',', FFAC_Access::allowed_slots({uid}));")
    check("the ticks were stored", stored == "2,4", stored)

    # --- and the member immediately sees exactly that -----------------------
    ctx3 = browser.new_context(viewport={"width": 1280, "height": 800})
    page3 = ctx3.new_page()
    member_login(page3, "samtaylor", "TestPass!2026")
    check("approved member can now sign in", "/members-menu/" in page3.url, page3.url)
    codes = [t.inner_text().split("\n")[0] for t in page3.locator(".ffac-tile--open").all()]
    check("they see exactly the two pages ticked", codes == ["Page-02", "Page-04"], str(codes))
    page3.screenshot(path=f"{SHOTS}/14-new-member-menu.png")

    page3.goto(f"{BASE}/page-01/", wait_until="domcontentloaded")
    check("and nothing else", "ffac=denied" in page3.url, page3.url)

    # --- search engines ------------------------------------------------------
    # Fetched raw, not through the browser: Chromium applies the XSL stylesheet
    # and the rendered DOM is not what a search engine reads.
    sitemap = urllib.request.urlopen(f"{BASE}/wp-sitemap-posts-page-1.xml").read().decode()
    check("member pages kept out of the XML sitemap",
          "/page-01/" not in sitemap and "/members-menu/" not in sitemap,
          "leaked" if "/page-01/" in sitemap else "clean")
    check("public pages still in the sitemap", "/about/" in sitemap and "/contact/" in sitemap)

    page3.goto(f"{BASE}/page-02/", wait_until="domcontentloaded")
    check("member page tells search engines not to index it",
          'name="robots" content="noindex, nofollow"' in page3.content(), page3.url)
    page3.goto(f"{BASE}/about/", wait_until="domcontentloaded")
    check("a public page is left indexable",
          'name="robots" content="noindex, nofollow"' not in page3.content())

    # --- username enumeration ------------------------------------------------
    anon = browser.new_context(viewport={"width": 1280, "height": 800})
    apg = anon.new_page()

    try:
        users_json = urllib.request.urlopen(f"{BASE}/wp-json/wp/v2/users").read().decode()
        status = 200
    except urllib.error.HTTPError as e:
        users_json = e.read().decode()
        status = e.code
    check("REST users endpoint closed to strangers",
          status == 404 and "famadmin" not in users_json and "janet" not in users_json,
          f"HTTP {status}")

    apg.goto(f"{BASE}/?author=1", wait_until="domcontentloaded")
    check("?author=1 does not reveal the admin username",
          "/author/" not in apg.url and "famadmin" not in apg.url, apg.url)

    sm_index = urllib.request.urlopen(f"{BASE}/wp-sitemap.xml").read().decode()
    check("author pages dropped from the sitemap index", "users" not in sm_index,
          "leaked" if "users" in sm_index else "clean")

    # A signed-in administrator must still get the endpoint, or the block editor and
    # Elementor lose the author fields. Checked against the filter itself: a browser
    # fetch without an X-WP-Nonce counts as anonymous to the REST API whatever cookies
    # it carries, so it cannot answer this question.
    kept = php("$u = get_user_by('login','famadmin'); wp_set_current_user($u->ID); "
               "$e = ['/wp/v2/users' => 1, '/wp/v2/posts' => 1]; "
               "$e = apply_filters('rest_endpoints', $e); "
               "echo implode(',', array_keys($e));")
    check("signed-in admin keeps the users endpoint", "/wp/v2/users" in kept, kept)

    # --- the admin help screen ----------------------------------------------
    apage.goto(f"{BASE}/wp-admin/admin.php?page=ffac-help", wait_until="domcontentloaded")
    check("instructions screen loads", "How to use Family Access" in apage.content())
    apage.screenshot(path=f"{SHOTS}/15-admin-help.png")

    apage.goto(f"{BASE}/wp-admin/admin.php?page=ffac-settings", wait_until="domcontentloaded")
    apage.screenshot(path=f"{SHOTS}/16-admin-settings.png")
    apage.goto(f"{BASE}/wp-admin/admin.php?page=ffac-pages", wait_until="domcontentloaded")
    apage.screenshot(path=f"{SHOTS}/17-admin-pages.png")

    browser.close()

failed = [r for r in results if not r[1]]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
