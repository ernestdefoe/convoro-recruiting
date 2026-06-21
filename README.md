# Recruiting for Convoro

A college-football recruiting tracker for [Convoro](https://convoro.co) — a
faithful port of the Flarum **Recruiting** extension. Pulls live FBS recruiting
rankings from [collegefootballdata.com](https://collegefootballdata.com) and
renders them on a themed page at **/recruiting**.

Third-party extension by Ernest Defoe. MIT licensed. Requires Convoro core
**≥ 1.39.6**.

## Features

- **Live FBS rankings** — the recruiting class for any year (or a single team),
  pulled straight from the College Football Data API.
- **Recruit cards** — national ranking, name, position, a five-star rating, the
  numeric rating, height/weight, hometown and committed school (or *Undecided*),
  with optional player headshots.
- **Client-side filters** — keyword search (name · high school · hometown ·
  school), a position dropdown built from the loaded data, and an
  All / Committed / Undecided toggle. No reloads — it all happens in the browser.
- **Fast + resilient** — a stale-while-revalidate cache keeps the page quick and
  keeps serving the last good rankings even if CFBD is temporarily unreachable.
- **No database** — entirely cache-backed; nothing to migrate.

## Setup

Install from the Marketplace, then under **Admin → Extensions → Recruiting**:

1. Add your **College Football Data API key** (free from
   [collegefootballdata.com/key](https://collegefootballdata.com/key)) — this is
   required to load any data.
2. Optionally set the **class year**, a **team** filter, the **max recruits**,
   the **cache duration**, whether to show **headshots**, and a custom **page
   title**.
3. Visit **/recruiting** (a header nav link is added automatically). Use
   **Refresh now** in the admin to warm the cache on demand.

A scheduled task (`php artisan convoro:recruiting-refresh`) keeps the cache warm
in the background on roughly the cache interval.

## Notes

- The **recruiting page requires a logged-in member** (matching the Flarum
  original, which returns 401 to guests).
- **Headshots** are scraped from On3's public rankings page on a best-effort
  basis and degrade gracefully to star-tier initials if unavailable. They can be
  switched off entirely in the admin.

## Credits

Ported from the Flarum extension `Ernestdefoe\Recruiting`. Recruiting data from
the College Football Data API. Headshots, when enabled, from On3.
