<?php

namespace Ernestdefoe\Recruiting;

use App\Support\ExtensionManager;
use App\Support\ExtPage;
use App\Support\Settings;
use Ernestdefoe\Recruiting\Service\RecruitData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Response;

/**
 * Recruiting — first-party-style Convoro extension by Ernest Defoe.
 *
 * A faithful Convoro-native port of the Flarum "Recruiting" extension. Pulls
 * live FBS recruiting rankings from the College Football Data API
 * (collegefootballdata.com) and renders them on a themed, server-rendered page
 * at /recruiting: a hero, a client-side filter bar (keyword search · position ·
 * commitment status) and a responsive grid of recruit cards (headshot or
 * initials fallback, national rank, star rating, hometown, committed school).
 *
 * Cache-backed (no DB): a stale-while-revalidate envelope keeps the page fast
 * and serves the last good data through CFBD outages. Optional On3 headshots.
 */
class Extension extends ServiceProvider
{
    private const ID = 'ernestdefoe-recruiting';

    public function boot(): void
    {
        $this->registerRoutes();

        // Scheduled cache warm. Cadence follows the admin's cache_minutes (clamped
        // to a sane 5 min..24 h band); also runnable by hand via
        // `php artisan convoro:recruiting-refresh`.
        if ($this->app->runningInConsole()) {
            $this->commands([RefreshCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Warm the cache on a cadence near cache_minutes. cron minute-steps
            // must be 1..59, so anything an hour or longer simply runs hourly.
            $minutes = max(5, (int) self::setting('cache_minutes', 360));
            $event = $schedule->command(RefreshCommand::class)
                ->name('ernestdefoe-recruiting-refresh')
                ->withoutOverlapping();
            $minutes >= 60 ? $event->hourly() : $event->cron('*/'.$minutes.' * * * *');
        });
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return ExtensionManager::setting(self::ID, $key, $default);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Only allow http(s) photo URLs (CFBD/On3 data is third-party). */
    private static function safePhoto(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) ? $url : null;
    }

    // --- Routes ------------------------------------------------------------

    private function registerRoutes(): void
    {
        // The recruiting page. The Flarum original 401s guests, so we require login.
        Route::middleware(['web', 'auth'])->get('/recruiting', function (RecruitData $data) {
            return self::recruitingPage($data->get());
        });

        // Admin: settings form + save + "Refresh now". Admin-area only.
        Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/recruiting')->group(function () {
            Route::get('/', fn () => self::adminPage());

            Route::post('/settings', function (Request $request) {
                $keys = ['api_key', 'year', 'team', 'max_recruits', 'cache_minutes', 'photos_enabled', 'widget_title'];
                foreach ($keys as $k) {
                    if ($request->has($k)) {
                        Settings::set(ExtensionManager::settingKey(self::ID, $k), $request->input($k));
                    }
                }

                return response()->json(['ok' => true]);
            });

            Route::post('/refresh', function (RecruitData $data) {
                $res = $data->warm();

                return response()->json([
                    'ok' => $res['error'] === null,
                    'count' => count($res['data']),
                    'year' => $res['year'],
                    'error' => $res['error'],
                ]);
            });
        });
    }

    // --- The /recruiting page ---------------------------------------------

    /**
     * @param  array{data: list<array<string,mixed>>, year: int, error: ?string}  $payload
     */
    private static function recruitingPage(array $payload): Response
    {
        $recruits = $payload['data'];
        $year = (int) $payload['year'];
        $error = $payload['error'];

        $titleSetting = trim((string) self::setting('widget_title', ''));
        $heroTitle = $titleSetting !== '' ? $titleSetting : ('FBS Recruiting '.$year);

        $hero = '<div class="rc-hero"><div class="rc-hero-inner">'
            .'<div class="rc-eyebrow">College Football</div>'
            .'<h1 class="rc-h1"><i class="rc-star">★</i> '.self::e($heroTitle).'</h1>'
            .'<p class="rc-sub">Live FBS recruiting rankings from collegefootballdata.com — search, filter by position, and track commitments.</p>'
            .'</div></div>';

        $body = self::errorBody($error, $year)
            ?? (empty($recruits)
                ? self::stateBody('🔎', 'No recruits found for '.$year.'.')
                : self::listBody($recruits));

        $page = '<div class="rc-wrap">'.$hero.$body.'</div>';

        return ExtPage::render('Recruiting', $page, self::css(), $error === null && ! empty($recruits) ? self::js() : '');
    }

    /** Friendly panel for a known error code, or null if there's no error. */
    private static function errorBody(?string $error, int $year): ?string
    {
        if ($error === null) {
            return null;
        }

        if ($error === 'api_key_missing') {
            return self::stateBody('🔑',
                'Set your College Football Data API key in Admin → Extensions → Recruiting to load recruiting rankings.',
                'warn');
        }
        if ($error === 'invalid_api_key') {
            return self::stateBody('⚠️',
                'The configured College Football Data API key was rejected. Check it in Admin → Extensions → Recruiting.',
                'warn');
        }
        if ($error === 'cfbd_unreachable') {
            return self::stateBody('📡',
                'Could not reach the College Football Data API right now. Please try again shortly.',
                'warn');
        }
        if (str_starts_with($error, 'cfbd_error_')) {
            $status = self::e(substr($error, strlen('cfbd_error_')));

            return self::stateBody('⚠️',
                'The College Football Data API returned an error (HTTP '.$status.'). Please try again shortly.',
                'warn');
        }

        return self::stateBody('⚠️', 'Recruiting data is unavailable right now. Please try again shortly.', 'warn');
    }

    private static function stateBody(string $icon, string $message, string $kind = ''): string
    {
        $cls = $kind === 'warn' ? ' rc-state-warn' : '';

        return '<div class="rc-state'.$cls.'"><div class="rc-state-ico">'.$icon.'</div>'
            .'<p>'.self::e($message).'</p></div>';
    }

    /**
     * The filter bar, stats line, and card grid. Cards carry data-* attributes
     * the client-side JS filters over — no re-fetch, pure DOM show/hide.
     *
     * @param  list<array<string,mixed>>  $recruits
     */
    private static function listBody(array $recruits): string
    {
        // Unique, ordered position list for the dropdown (from the loaded data).
        $positions = [];
        foreach ($recruits as $r) {
            $pos = (string) ($r['position'] ?? '');
            if ($pos !== '' && ! in_array($pos, $positions, true)) {
                $positions[] = $pos;
            }
        }
        $posOptions = '<option value="">All positions</option>';
        foreach ($positions as $p) {
            $posOptions .= '<option value="'.self::e($p).'">'.self::e($p).'</option>';
        }

        $committed = 0;
        $ratingSum = 0.0;
        foreach ($recruits as $r) {
            if (($r['status'] ?? '') === 'committed') {
                $committed++;
            }
            $ratingSum += (float) ($r['rating'] ?? 0);
        }
        $avgRating = count($recruits) ? number_format($ratingSum / count($recruits), 4) : null;

        $filters = '<div class="rc-filters">'
            .'<div class="rc-search-wrap"><span class="rc-search-ico">🔎</span>'
            .'<input id="rc-search" class="rc-search" type="text" placeholder="Search name, school, hometown…" autocomplete="off"></div>'
            .'<select id="rc-pos" class="rc-select">'.$posOptions.'</select>'
            .'<select id="rc-status" class="rc-select">'
            .'<option value="all">All recruits</option>'
            .'<option value="committed">Committed only</option>'
            .'<option value="undecided">Undecided only</option>'
            .'</select>'
            .'</div>';

        $stats = '<div class="rc-stats" id="rc-stats" '
            .'data-total="'.count($recruits).'" '
            .'data-committed="'.$committed.'"'
            .($avgRating !== null ? ' data-avg="'.self::e($avgRating).'"' : '')
            .'><span id="rc-count">'.count($recruits).' recruits</span>'
            .($avgRating !== null ? '<span>Avg rating: '.self::e($avgRating).'</span>' : '')
            .'<span id="rc-committed">'.$committed.' committed</span>'
            .'</div>';

        $cards = '';
        foreach ($recruits as $r) {
            $cards .= self::card($r);
        }

        $empty = '<div class="rc-state rc-empty" id="rc-empty" hidden><div class="rc-state-ico">🔎</div><p>No recruits match your filters.</p></div>';

        return $filters.$stats.'<div class="rc-grid" id="rc-grid">'.$cards.'</div>'.$empty;
    }

    /**
     * One recruit card. All third-party CFBD/On3 strings are escaped; the photo
     * URL is restricted to http(s). The data-* attributes feed the client
     * filters (search haystack, position, status).
     *
     * @param  array<string,mixed>  $r
     */
    private static function card(array $r): string
    {
        $name = (string) ($r['name'] ?? 'Unknown');
        $position = $r['position'] !== null ? (string) $r['position'] : null;
        $ranking = $r['ranking'] !== null ? (int) $r['ranking'] : null;
        $rating = $r['rating'] !== null ? (float) $r['rating'] : null;
        $stars = $r['stars'] !== null ? (int) $r['stars'] : null;
        $status = ($r['status'] ?? '') === 'committed' ? 'committed' : 'undecided';
        $school = $r['school'] !== null ? (string) $r['school'] : null;
        $highSchool = $r['highSchool'] !== null ? (string) $r['highSchool'] : null;
        $hometown = $r['hometown'] !== null ? (string) $r['hometown'] : null;
        $country = $r['country'] !== null ? (string) $r['country'] : null;
        $height = $r['height'] !== null ? (string) $r['height'] : null;
        $weight = $r['weight'] !== null ? (string) $r['weight'] : null;
        $recruitType = (string) ($r['recruitType'] ?? 'HighSchool');
        $photo = self::safePhoto($r['photoUrl'] ?? null);

        // Search haystack (lower-cased), built from the fields the original page
        // searched: name, high school, hometown, committed school.
        $haystack = strtolower(trim(implode(' ', array_filter([$name, $highSchool, $hometown, $school]))));

        // Top bar: rank · stars · rating.
        $top = '<div class="rc-top">'
            .($ranking !== null ? '<span class="rc-rank">#'.$ranking.'</span>' : '<span class="rc-rank rc-rank-none">—</span>')
            .(self::starsStr($stars) !== null ? '<span class="rc-stars">'.self::starsStr($stars).'</span>' : '')
            .($rating !== null ? '<span class="rc-rating">'.self::e(number_format($rating, 4)).'</span>' : '')
            .'</div>';

        // Photo or star-tier initials fallback.
        $photoBlock = '<div class="rc-photo-wrap" data-stars="'.(int) ($stars ?? 0).'">'
            .($photo !== null
                ? '<img class="rc-photo" src="'.self::e($photo).'" alt="'.self::e($name).'" loading="lazy" onerror="this.parentNode.classList.add(\'rc-photo-failed\')">'
                : '')
            .'<span class="rc-initials">'.self::e(self::initials($name)).'</span>'
            .'</div>';

        $physicals = implode(' · ', array_filter([$height, $weight]));

        $body = '<div class="rc-body">'
            .'<div class="rc-name">'.self::e($name).'</div>'
            .($position !== null ? '<span class="rc-pos">'.self::e($position).'</span>' : '')
            .($physicals !== '' ? '<div class="rc-physicals">'.self::e($physicals).'</div>' : '')
            .($highSchool !== null ? '<div class="rc-detail">🏫 '.self::e($highSchool).'</div>' : '')
            .($hometown !== null
                ? '<div class="rc-detail">📍 '.self::e($hometown).($country !== null ? '<span class="rc-country"> · '.self::e($country).'</span>' : '').'</div>'
                : '')
            .($recruitType !== '' && $recruitType !== 'HighSchool'
                ? '<span class="rc-type">'.self::e(trim(preg_replace('/([A-Z])/', ' $1', $recruitType))).'</span>'
                : '')
            .'</div>';

        $footer = '<div class="rc-footer">'
            .($status === 'committed'
                ? '<span class="rc-commit rc-commit-yes">✔ '.self::e($school ?? 'Committed').'</span>'
                : '<span class="rc-commit rc-commit-no">○ Undecided</span>')
            .'</div>';

        return '<div class="rc-card" data-search="'.self::e($haystack).'" data-position="'.self::e((string) $position).'" data-status="'.$status.'">'
            .$top.$photoBlock.$body.$footer.'</div>';
    }

    /** "★★★☆☆" for n stars (1–5), or null for none. */
    private static function starsStr(?int $n): ?string
    {
        if (! $n || $n < 1) {
            return null;
        }
        $full = min(5, $n);
        $empty = max(0, 5 - $full);

        return str_repeat('★', $full).str_repeat('☆', $empty);
    }

    /** Two-letter initials from a full name. */
    private static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }

    // --- Admin settings page ----------------------------------------------

    private static function adminPage(): Response
    {
        $apiKey = self::e(self::setting('api_key', ''));
        $year = self::e(self::setting('year', ''));
        $team = self::e(self::setting('team', ''));
        $maxRecruits = (int) self::setting('max_recruits', 25);
        $cacheMinutes = (int) self::setting('cache_minutes', 360);
        $photosOn = (bool) self::setting('photos_enabled', true) ? ' checked' : '';
        $widgetTitle = self::e(self::setting('widget_title', ''));

        $body = <<<HTML
        <div class="rc-wrap rc-narrow">
          <div class="rc-admin-hero">
            <div class="rc-eyebrow">Recruiting</div>
            <h1 class="rc-h1">CFBD recruiting rankings</h1>
            <p class="rc-sub">Live FBS recruiting rankings shown on the <a href="/recruiting" target="_blank">Recruiting</a> page. Add your <a href="https://collegefootballdata.com/key" target="_blank" rel="noopener">College Football Data API key</a> below. Changes save automatically.</p>
          </div>
          <div class="rc-card-form">
            <label class="rc-f">College Football Data API key</label>
            <input id="api_key" type="text" value="{$apiKey}" placeholder="Paste your CFBD API key" autocomplete="off">
            <p class="rc-hint">Free from <a href="https://collegefootballdata.com/key" target="_blank" rel="noopener">collegefootballdata.com/key</a>. Required to load any data.</p>

            <label class="rc-f">Recruiting class year</label>
            <input id="year" type="text" value="{$year}" placeholder="Leave blank for the current year (e.g. 2026)">

            <label class="rc-f">Team (optional)</label>
            <input id="team" type="text" value="{$team}" placeholder="e.g. Alabama — leave blank for all FBS">

            <label class="rc-f">Max recruits</label>
            <input id="max_recruits" type="number" min="1" max="100" value="{$maxRecruits}">

            <label class="rc-f">Cache (minutes)</label>
            <input id="cache_minutes" type="number" min="1" value="{$cacheMinutes}">
            <p class="rc-hint">How long fetched rankings stay fresh before a background refresh. Stale data still shows if CFBD is down.</p>

            <label class="rc-check"><input id="photos_enabled" type="checkbox"{$photosOn}> Show player headshots (scraped from On3)</label>

            <label class="rc-f">Page title (optional)</label>
            <input id="widget_title" type="text" value="{$widgetTitle}" placeholder="Defaults to “FBS Recruiting {year}”">

            <div class="rc-actions">
              <button type="button" class="rc-btn rc-btn-ghost" id="refresh">Refresh now</button>
              <span class="rc-saved" id="saved"></span>
            </div>
          </div>
        </div>
        HTML;

        return ExtPage::render('Recruiting', $body, self::css(), self::adminJs());
    }

    private static function adminJs(): string
    {
        return <<<'JS'
        function notify(m,k){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:m,kind:k||'success'},location.origin);}catch(e){}}
        var savedEl=document.getElementById('saved');
        var savedTimer=null;
        function flagSaved(){if(savedEl){savedEl.textContent='Saved';if(savedTimer)clearTimeout(savedTimer);savedTimer=setTimeout(function(){savedEl.textContent='';},1500);}}
        var saveTimer=null;
        function collect(){
          return {
            api_key:document.getElementById('api_key').value.trim(),
            year:document.getElementById('year').value.trim(),
            team:document.getElementById('team').value.trim(),
            max_recruits:document.getElementById('max_recruits').value,
            cache_minutes:document.getElementById('cache_minutes').value,
            photos_enabled:document.getElementById('photos_enabled').checked?1:0,
            widget_title:document.getElementById('widget_title').value.trim()
          };
        }
        function save(){
          fetch('/admin/ext/recruiting/settings',{method:'POST',headers:H,body:JSON.stringify(collect())})
            .then(function(r){if(r.ok){flagSaved();}else{notify('Could not save settings','error');}})
            .catch(function(){notify('Could not save settings','error');});
        }
        function queueSave(){if(saveTimer)clearTimeout(saveTimer);saveTimer=setTimeout(save,500);}
        ['api_key','year','team','max_recruits','cache_minutes','widget_title'].forEach(function(id){
          var el=document.getElementById(id);if(el)el.addEventListener('input',queueSave);
        });
        var photos=document.getElementById('photos_enabled');if(photos)photos.addEventListener('change',function(){save();});
        document.getElementById('refresh').addEventListener('click',function(){
          var btn=this;btn.disabled=true;var old=btn.textContent;btn.textContent='Refreshing…';
          // Save current settings first so the refresh uses them.
          fetch('/admin/ext/recruiting/settings',{method:'POST',headers:H,body:JSON.stringify(collect())})
            .then(function(){return fetch('/admin/ext/recruiting/refresh',{method:'POST',headers:H});})
            .then(function(r){return r.json();})
            .then(function(d){
              btn.disabled=false;btn.textContent=old;
              if(d&&d.ok){notify('Refreshed — cached '+d.count+' recruit(s) for '+d.year);}
              else{notify('Refresh failed'+(d&&d.error?' ('+d.error+')':''),'error');}
            })
            .catch(function(){btn.disabled=false;btn.textContent=old;notify('Refresh failed','error');});
        });
        JS;
    }

    // --- Page interactivity (client-side filters over rendered cards) ------

    private static function js(): string
    {
        return <<<'JS'
        (function(){
          var search=document.getElementById('rc-search');
          var posSel=document.getElementById('rc-pos');
          var statusSel=document.getElementById('rc-status');
          var grid=document.getElementById('rc-grid');
          var empty=document.getElementById('rc-empty');
          var countEl=document.getElementById('rc-count');
          var committedEl=document.getElementById('rc-committed');
          if(!grid)return;
          var cards=Array.prototype.slice.call(grid.querySelectorAll('.rc-card'));
          function apply(){
            var q=(search&&search.value||'').trim().toLowerCase();
            var pos=posSel&&posSel.value||'';
            var st=statusSel&&statusSel.value||'all';
            var shown=0,committed=0;
            cards.forEach(function(c){
              var ok=true;
              if(pos&&c.getAttribute('data-position')!==pos)ok=false;
              if(ok&&st!=='all'&&c.getAttribute('data-status')!==st)ok=false;
              if(ok&&q&&(c.getAttribute('data-search')||'').indexOf(q)===-1)ok=false;
              c.style.display=ok?'':'none';
              if(ok){shown++;if(c.getAttribute('data-status')==='committed')committed++;}
            });
            if(countEl)countEl.textContent=shown+(shown===1?' recruit':' recruits');
            if(committedEl)committedEl.textContent=committed+' committed';
            if(empty)empty.hidden=shown!==0;
            if(grid)grid.style.display=shown===0?'none':'';
          }
          if(search)search.addEventListener('input',apply);
          if(posSel)posSel.addEventListener('change',apply);
          if(statusSel)statusSel.addEventListener('change',apply);
        })();
        JS;
    }

    // --- Shared styling ----------------------------------------------------

    private static function css(): string
    {
        return <<<'CSS'
        .rc-wrap{max-width:1100px;margin:0 auto;padding:24px 16px 64px}
        .rc-narrow{max-width:680px}
        .ext-embed .rc-wrap{padding:0}
        .rc-hero,.rc-admin-hero{padding:28px 30px;margin-bottom:22px;border-radius:18px;border:1px solid rgb(var(--c-border));
          background:linear-gradient(135deg,rgba(178,34,34,.18),rgba(220,38,38,.08)),rgb(var(--c-surface))}
        .rc-admin-hero{padding:22px 24px}
        .rc-hero-inner{max-width:760px}
        .rc-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:#b22222;margin-bottom:6px;text-transform:uppercase}
        .rc-h1{font-size:1.9rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text));display:flex;align-items:center;gap:8px}
        .rc-star{font-style:normal;color:#f5b301}
        .rc-sub{margin:8px 0 0;color:rgb(var(--c-text-2));font-size:14px;line-height:1.55}
        .rc-sub a{color:rgb(var(--c-primary));font-weight:600;text-decoration:none}
        .rc-state{text-align:center;padding:54px 20px;border:1.5px dashed rgb(var(--c-border));border-radius:16px;color:rgb(var(--c-text-2))}
        .rc-state-ico{font-size:38px;margin-bottom:8px}
        .rc-state p{margin:0;font-size:15px}
        .rc-state-warn{border-color:rgb(var(--c-primary)/.5);background:rgb(var(--c-primary)/.05)}
        .rc-filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
        .rc-search-wrap{position:relative;flex:1;min-width:220px}
        .rc-search-ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;font-size:14px}
        .rc-search{width:100%;font:inherit;font-size:14px;padding:10px 12px 10px 34px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .rc-select{font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface-2));color:rgb(var(--c-text));cursor:pointer}
        .rc-search:focus,.rc-select:focus{outline:none;border-color:rgb(var(--c-primary))}
        .rc-stats{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:rgb(var(--c-muted));margin-bottom:16px;padding:0 2px}
        .rc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
        .rc-card{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
        .rc-top{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid rgb(var(--c-border))}
        .rc-rank{font-weight:900;font-size:14px;color:rgb(var(--c-text))}
        .rc-rank-none{color:rgb(var(--c-muted))}
        .rc-stars{color:#f5b301;font-size:13px;letter-spacing:1px}
        .rc-rating{margin-left:auto;font-size:12px;font-weight:700;color:rgb(var(--c-text-2));
          background:rgb(var(--c-surface-2));border-radius:999px;padding:2px 8px}
        .rc-photo-wrap{position:relative;width:100%;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;
          background:linear-gradient(135deg,rgb(var(--c-surface-2)),rgb(var(--c-surface)))}
        .rc-photo-wrap[data-stars="5"]{background:linear-gradient(135deg,rgba(245,179,1,.25),rgb(var(--c-surface)))}
        .rc-photo-wrap[data-stars="4"]{background:linear-gradient(135deg,rgba(178,34,34,.20),rgb(var(--c-surface)))}
        .rc-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
        .rc-initials{font-size:2.4rem;font-weight:900;color:rgb(var(--c-muted))}
        .rc-photo-failed .rc-photo{display:none}
        .rc-body{padding:12px 13px 6px;flex:1}
        .rc-name{font-weight:800;font-size:15px;color:rgb(var(--c-text));line-height:1.25}
        .rc-pos{display:inline-block;font-size:11px;font-weight:800;letter-spacing:.03em;color:#b22222;
          background:rgba(178,34,34,.10);border-radius:6px;padding:2px 7px;margin:6px 0 2px}
        .rc-physicals{font-size:12.5px;color:rgb(var(--c-muted));margin:3px 0}
        .rc-detail{font-size:12.5px;color:rgb(var(--c-text-2));margin-top:5px}
        .rc-country{color:rgb(var(--c-muted))}
        .rc-type{display:inline-block;font-size:11px;font-weight:700;color:rgb(var(--c-text-2));
          background:rgb(var(--c-surface-2));border-radius:6px;padding:2px 7px;margin-top:7px}
        .rc-footer{padding:8px 13px 12px}
        .rc-commit{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;border-radius:999px;padding:4px 10px}
        .rc-commit-yes{color:#059669;background:rgba(16,185,129,.12)}
        .rc-commit-no{color:rgb(var(--c-muted));background:rgb(var(--c-surface-2))}
        .rc-empty{margin-top:8px}
        /* Admin form */
        .rc-card-form{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:22px}
        .rc-f{display:block;font-size:13px;font-weight:600;color:rgb(var(--c-text-2));margin:16px 0 5px}
        .rc-card-form input[type=text],.rc-card-form input[type=number]{width:100%;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .rc-card-form input:focus{outline:none;border-color:rgb(var(--c-primary))}
        .rc-hint{font-size:12px;color:rgb(var(--c-muted));margin:5px 0 0}
        .rc-hint a{color:rgb(var(--c-primary));text-decoration:none}
        .rc-check{display:flex;align-items:center;gap:8px;font-size:14px;color:rgb(var(--c-text));margin:18px 0 4px;cursor:pointer}
        .rc-check input{width:auto}
        .rc-actions{display:flex;align-items:center;gap:12px;margin-top:20px}
        .rc-btn{font:inherit;font-size:13.5px;font-weight:700;padding:9px 15px;border-radius:10px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .rc-btn-ghost{background:rgb(var(--c-surface-2))}
        .rc-btn:disabled{opacity:.6;cursor:default}
        .rc-saved{font-size:13px;color:#059669;font-weight:600}
        CSS;
    }
}
