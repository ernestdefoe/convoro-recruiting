<?php

namespace Ernestdefoe\Recruiting\Service;

use App\Support\ExtensionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Augments recruit rows with headshot URLs scraped from On3's rankings page.
 *
 * Strategy:
 *   Fetch https://www.on3.com/rivals/rankings/player/football/{year}/ once.
 *   The page is server-rendered HTML with ~150 players, each with a profile
 *   href (`/rivals/{slug}-{id}/`) and an on3static.com image. We build a
 *   `name-slug → image-URL` map and cache it for 24 hours; an empty map
 *   (failed scrape) is NOT cached so the next request retries.
 *
 * Everything here fails SOFT: any network/parse failure yields no photos
 * rather than an error — recruits still render with initials avatars.
 *
 * NOTE (fragility): this depends on On3's public HTML layout and a proximity
 * heuristic to pair images with profile links. If On3 changes their markup
 * the map will come back small/empty and recruits silently fall back to
 * initials. Operators can switch the whole scrape off via `photos_enabled`.
 */
class On3PhotoEnricher
{
    private const ID = 'ernestdefoe-recruiting';

    private const RANKINGS_URL = 'https://www.on3.com/rivals/rankings/player/football/';

    private const STATIC_HOST = 'https://on3static.com';

    private const CACHE_TTL = 24 * 3600;

    /** Request timeout (seconds) for the rankings fetch. */
    private const TIMEOUT = 12;

    /**
     * Honest User-Agent identifying this extension. We deliberately do NOT
     * spoof a browser UA: scraping with a forged identity risks the forum's IP
     * being WAF-blocked. If On3 stops serving usable HTML to this UA, operators
     * should disable photo enrichment rather than re-introduce spoofing.
     */
    private const USER_AGENT = 'convoro-recruiting (Convoro extension; +https://github.com/ernestdefoe/convoro-recruiting)';

    /**
     * A healthy rankings page yields ~150 players. Fewer than this after a
     * successful (HTTP 200) fetch signals the page layout changed and the
     * proximity parser is silently failing — worth a warning to the log.
     */
    private const MIN_EXPECTED_PLAYERS = 10;

    /**
     * Attach photoUrl to every recruit by looking up the name-slug in the
     * cached On3 image map.
     *
     * @param  list<array<string, mixed>>  $recruits
     * @return list<array<string, mixed>>
     */
    public function enrich(array $recruits, string $year): array
    {
        // Operators can disable the On3 scrape entirely (it's an outbound,
        // unauthenticated request to a third-party site). When off, recruits
        // render with star-tier initials avatars instead of headshots.
        if (! $this->enabled()) {
            return $recruits;
        }

        $imageMap = $this->imageMap($year);
        if (empty($imageMap)) {
            return $recruits;
        }

        return array_map(function ($r) use ($imageMap) {
            $slug = $this->nameToSlug((string) ($r['name'] ?? ''));
            $r['photoUrl'] = $imageMap[$slug] ?? null;

            return $r;
        }, $recruits);
    }

    /**
     * Fetch the year's image map, cached for 24 h. Empty maps are NOT cached —
     * a failed scrape retries on the next call.
     *
     * @return array<string, string>
     */
    private function imageMap(string $year): array
    {
        $cacheKey = 'ernestdefoe-recruiting.on3map.football.'.$year;

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->build($year);
        if (! empty($map)) {
            Cache::put($cacheKey, $map, self::CACHE_TTL);
        }

        return $map;
    }

    /**
     * Fetch + parse the rankings page. Returns empty array on any network or
     * parse failure — graceful degradation: the rest of the page still
     * renders without headshots.
     *
     * @return array<string, string>
     */
    private function build(string $year): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get(self::RANKINGS_URL.$year.'/');
        } catch (\Throwable $e) {
            Log::warning('[recruiting] On3 rankings fetch failed', ['exception' => $e->getMessage()]);

            return [];
        }

        if ($response->status() !== 200) {
            Log::warning('[recruiting] On3 rankings HTTP '.$response->status());

            return [];
        }

        $map = $this->parse((string) $response->body());
        $count = count($map);

        if ($count > 0 && $count < self::MIN_EXPECTED_PLAYERS) {
            // Fetch succeeded (HTTP 200) but the proximity parser found almost
            // nothing — On3's page layout has likely changed and is silently
            // breaking headshots. Surface it instead of failing mute.
            Log::warning('[recruiting] On3 rankings parsed only '.$count
                .' players (expected >= '.self::MIN_EXPECTED_PLAYERS
                .') — On3 page layout may have changed', ['year' => $year]);
        }

        return $map;
    }

    /**
     * Parse the rankings HTML into a slug → CDN URL map.
     *
     * Strategy:
     *  1. Collect all on3static.com player image positions.
     *  2. Collect all /rivals/{name}-{id}/ profile-path positions.
     *  3. For each unique profile path, find the closest image (≤ 5 000 chars).
     *  4. Store as nameSlug → full CDN URL (cdn-cgi resize prefix stripped).
     *
     * @return array<string, string>
     */
    private function parse(string $html): array
    {
        $imgPattern = '~https://on3static\.com(?:/cdn-cgi/image/[^\s"\'>\]]+)?'
                    .'(/uploads/assets/\d+/\d+/\d+\.(?:jpg|jpeg|png|webp))~i';

        preg_match_all($imgPattern, $html, $imgAll, PREG_OFFSET_CAPTURE);
        preg_match_all(
            '~/rivals/([a-z0-9][a-z0-9\-]+)-(\d{4,})/~i',
            $html,
            $hrefAll,
            PREG_OFFSET_CAPTURE
        );

        if (empty($imgAll[0]) || empty($hrefAll[0])) {
            return [];
        }

        $map = [];
        $seen = [];

        foreach ($hrefAll[0] as $i => [, $hrefPos]) {
            $nameSlug = $hrefAll[1][$i][0];

            if (empty($nameSlug) || isset($seen[$nameSlug])) {
                continue;
            }
            $seen[$nameSlug] = true;

            $bestDist = PHP_INT_MAX;
            $bestPath = null;

            foreach ($imgAll[0] as $j => [, $imgPos]) {
                $dist = abs($hrefPos - $imgPos);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestPath = $imgAll[1][$j][0];
                }
            }

            if ($bestPath !== null && $bestDist < 5000) {
                $map[$nameSlug] = self::STATIC_HOST.$bestPath;
            }
        }

        return $map;
    }

    /**
     * "Jared Curtis" → "jared-curtis"
     * "C.J. Stroud"  → "cj-stroud"
     */
    private function nameToSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Whether On3 photo enrichment is enabled. Defaults to on (preserving
     * existing behaviour) but operators can switch it off to stop all outbound
     * On3 traffic.
     */
    private function enabled(): bool
    {
        return (bool) ExtensionManager::setting(self::ID, 'photos_enabled', true);
    }
}
