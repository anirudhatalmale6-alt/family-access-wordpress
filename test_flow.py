"""End-to-end check of the Family Access plugin on the local WordPress."""
import re
import sys
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8710"
SHOTS = "/var/lib/freelancer/projects/40710857/shots"
results = []


def check(name, condition, detail=""):
    results.append((name, bool(condition), detail))
    print(("PASS  " if condition else "FAIL  ") + name + ("  " + detail if detail else ""))


def login(page, user, password="FamilyTest!2026"):
    page.goto(f"{BASE}/login/", wait_until="domcontentloaded")
    page.fill("#ffac-login-username", user)
    page.fill("#ffac-login-password", password)
    page.click("button[type=submit]")
    page.wait_for_load_state("domcontentloaded")


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1280, "height": 800})
    page = ctx.new_page()

    # 1. Logged out: a protected page must bounce to login.
    page.goto(f"{BASE}/page-01/", wait_until="domcontentloaded")
    check("logged-out visitor bounced from Page-01", "/login/" in page.url, page.url)
    check("login page explains why", "sign in to open that page" in page.content().lower())
    page.screenshot(path=f"{SHOTS}/01-login-bounce.png")

    # 2. Login as Janet (slots 1,3,5,12).
    login(page, "janet")
    check("janet lands on the menu page", "/members-menu/" in page.url, page.url)
    open_tiles = page.locator(".ffac-tile--open")
    locked_tiles = page.locator(".ffac-tile--locked")
    check("4 tiles open for janet", open_tiles.count() == 4, f"got {open_tiles.count()}")
    check("8 tiles greyed for janet", locked_tiles.count() == 8, f"got {locked_tiles.count()}")
    codes = [t.inner_text().split("\n")[0] for t in open_tiles.all()]
    check("open tiles are 01,03,05,12", codes == ["Page-01", "Page-03", "Page-05", "Page-12"], str(codes))
    page.screenshot(path=f"{SHOTS}/02-menu-janet.png")

    # 3. A greyed tile does nothing when clicked.
    before = page.url
    locked_tiles.first.click(force=True)
    page.wait_for_timeout(600)
    check("clicking a greyed tile goes nowhere", page.url == before, page.url)

    # 4. An allowed tile opens its page.
    page.locator(".ffac-tile--open").first.click()
    page.wait_for_load_state("domcontentloaded")
    check("allowed tile opens Page-01", "/page-01/" in page.url, page.url)
    page.screenshot(path=f"{SHOTS}/03-page-01.png")

    # 5. Typing the URL of a forbidden page directly is blocked.
    page.goto(f"{BASE}/page-02/", wait_until="domcontentloaded")
    check("direct URL to a forbidden page is blocked", "/members-menu/" in page.url, page.url)
    check("denied message shown", "has not been unlocked" in page.content())
    page.screenshot(path=f"{SHOTS}/04-denied.png")

    # 6. Search must not leak forbidden page titles.
    page.goto(f"{BASE}/?s=Page", wait_until="domcontentloaded")
    body = page.locator("body").inner_text()
    hits = sorted(set(re.findall(r"Page-\d\d", body)))
    check("search shows only the pages janet may open",
          hits == ["Page-01", "Page-03", "Page-05", "Page-12"], str(hits))

    # 7. Sign out, then confirm the username is remembered but not the session.
    page.goto(f"{BASE}/members-menu/", wait_until="domcontentloaded")
    page.click("a.ffac-btn--quiet")
    page.wait_for_load_state("domcontentloaded")
    check("signed out lands on login", "/login/" in page.url, page.url)
    check("logout confirmed on screen", "signed out" in page.content().lower())
    check("username remembered", page.input_value("#ffac-login-username") == "janet")
    check("greeted by first name", "Welcome back" in page.content())
    check("password box empty", page.input_value("#ffac-login-password") == "")
    page.screenshot(path=f"{SHOTS}/05-returning-user.png")

    # 8. A brand new browser session must not be logged in automatically.
    page.goto(f"{BASE}/members-menu/", wait_until="domcontentloaded")
    check("no auto-login after signing out", "/login/" in page.url, page.url)

    # 9. Tom sees a different set.
    login(page, "tom")
    check("tom has 2 open tiles", page.locator(".ffac-tile--open").count() == 2,
          str(page.locator(".ffac-tile--open").count()))
    check("tom cannot see Page-03", page.locator(".ffac-tile--locked", has_text="Page-03").count() == 1)
    page.screenshot(path=f"{SHOTS}/06-menu-tom.png")
    page.goto(f"{BASE}/page-05/", wait_until="domcontentloaded")
    check("tom blocked from Page-05", "/members-menu/" in page.url, page.url)

    # 10. A pending member cannot sign in at all.
    ctx2 = browser.new_context(viewport={"width": 1280, "height": 800})
    page2 = ctx2.new_page()
    login(page2, "newbie")
    check("pending member refused", "/login/" in page2.url, page2.url)
    check("pending member told why", "waiting to be approved" in page2.content().lower())
    page2.screenshot(path=f"{SHOTS}/07-pending.png")

    # 11. Wrong password gives a vague answer and does not leak usernames.
    login(page2, "janet", "totally-wrong")
    body = page2.content()
    check("bad password rejected", "did not match" in body)
    check("no hint about which half was wrong", "incorrect password" not in body.lower())

    # 12. Registration form: honeypot, sum and the tick box.
    page2.goto(f"{BASE}/register/", wait_until="domcontentloaded")
    check("register form has the not-a-robot tick box", page2.locator("input[name=ffac_human]").count() == 1)
    check("register form has the sum", page2.locator("#ffac-sum").count() == 1)
    hp = page2.locator("input[name=ffac_website]")
    hp_box = hp.bounding_box() if hp.count() else None
    check("honeypot present and off screen",
          hp.count() == 1 and hp_box is not None and hp_box["x"] < 0, str(hp_box))
    page2.screenshot(path=f"{SHOTS}/08-register.png")

    # Submit with the wrong sum -> rejected, typed values kept.
    sum_label = page2.locator("label[for=ffac-sum]").inner_text()
    page2.fill("#ffac-first_name", "Sam")
    page2.fill("#ffac-last_name", "Taylor")
    page2.fill("#ffac-relationship", "nephew")
    page2.fill("#ffac-username", "samtaylor")
    page2.fill("#ffac-email", "sam@example.invalid")
    page2.fill("#ffac-password", "TestPass!2026")
    page2.fill("#ffac-password2", "TestPass!2026")
    page2.check("input[name=ffac_human]")
    page2.fill("#ffac-sum", "999")
    page2.wait_for_timeout(4500)  # beat the minimum fill-in time
    page2.click("button[type=submit]")
    page2.wait_for_load_state("domcontentloaded")
    check("wrong sum rejected", "sum was not correct" in page2.content())
    check("typed details preserved", page2.input_value("#ffac-username") == "samtaylor")

    # Submit correctly -> account created, pending.
    nums = [int(n) for n in re.findall(r"\d+", sum_label)]
    page2.fill("#ffac-password", "TestPass!2026")
    page2.fill("#ffac-password2", "TestPass!2026")
    page2.check("input[name=ffac_human]")
    new_label = page2.locator("label[for=ffac-sum]").inner_text()
    nums = [int(n) for n in re.findall(r"\d+", new_label)]
    page2.fill("#ffac-sum", str(nums[0] + nums[1]))
    page2.wait_for_timeout(4500)
    page2.click("button[type=submit]")
    page2.wait_for_load_state("domcontentloaded")
    check("registration accepted", "waiting to be approved" in page2.content().lower()
          or "sent to the site owner" in page2.content().lower(), page2.url)
    page2.screenshot(path=f"{SHOTS}/09-registered.png")

    # The brand new account must not be able to sign in yet.
    login(page2, "samtaylor", "TestPass!2026")
    check("new account cannot sign in before approval", "/login/" in page2.url and
          "waiting to be approved" in page2.content().lower(), page2.url)

    browser.close()

failed = [r for r in results if not r[1]]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
