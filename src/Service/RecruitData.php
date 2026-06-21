<?php

namespace Ernestdefoe\Recruiting\Service;

use App\Support\ExtensionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the recruit list for the /recruiting page: resolves settings,
 * applies the stale-while-revalidate cache envelope, fetches from CFBD via
 * CfbdClient, and enriches with On3 headshots — all server-side so ExtPage can
 * render the cards in one pass.
 *
 * Cache shape:
 *   $cacheKey -> ['data' => list<recruit>, 'fetched_at' => unix ts]
 *
 * Stale-while-revalidate: a soft TTL (cache_minutes) marks data "stale"; when
 * stale we re-fetch synchronously, but if the fetch FAILS we serve the last
 * good cache (retained ~7 days hard) rather than erroring. So a string of CFBD
 * outages never blanks the page — we'd rather show data that's a few days old.
 *
 * Return shape (always an array):
 *   ['data' => list<recruit>, 'year' => int, 'error' => ?string]
 * where error is one of: api_key_missing, cfbd_unreachable, invalid_api_key,
 * cfbd_error_<status>, unexpected_error — or null on success.
 */
class RecruitData
{
    private const ID = 'ernestdefoe-recruiting';

    /** Hard cache retention. Stale data is still better than no data. */
    private const HARD_RETENTION_SECONDS = 7 * 24 * 3600;

    public function __construct(
        private readonly CfbdClient $cfbd,
        private readonly On3PhotoEnricher $photos,
    ) {}

    private static function setting(string $key, mixed $default = null): mixed
    {
        return ExtensionManager::setting(self::ID, $key, $default);
    }

    /** Resolve the configured (or current) four-digit year. */
    public static function resolveYear(): string
    {
        $year = trim((string) self::setting('year', ''));

        return ($year !== '' && preg_match('/^\d{4}$/', $year)) ? $year : (string) date('Y');
    }

    /** Cache key for the current settings tuple (year|team|maxRecruits). */
    private static function cacheKey(string $year, string $team, int $maxRecruits): string
    {
        return 'ernestdefoe-recruiting.'.md5("{$year}|{$team}|{$maxRecruits}");
    }

    /**
     * The recruit list for the page. Never throws — failures surface as an
     * `error` code so the page can render a friendly state.
     *
     * @param  bool  $forceRefresh  bypass the cache and re-fetch (admin "Refresh now")
     * @return array{data: list<array<string,mixed>>, year: int, error: ?string}
     */
    public function get(bool $forceRefresh = false): array
    {
        $apiKey = trim((string) self::setting('api_key', ''));
        $team = trim((string) self::setting('team', ''));
        $maxRecruits = max(1, min(100, (int) self::setting('max_recruits', 25)));
        $softTtl = max(60, (int) self::setting('cache_minutes', 360) * 60);
        $year = self::resolveYear();

        if ($apiKey === '') {
            return ['data' => [], 'year' => (int) $year, 'error' => 'api_key_missing'];
        }

        $cacheKey = self::cacheKey($year, $team, $maxRecruits);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);
        $haveCache = is_array($cached) && isset($cached['data'], $cached['fetched_at']);

        // Fresh enough? serve it.
        if ($haveCache && ! $forceRefresh && (time() - (int) $cached['fetched_at']) <= $softTtl) {
            return ['data' => $cached['data'], 'year' => (int) $year, 'error' => null];
        }

        // Cold, stale, or forced: try a fresh fetch. On failure, fall back to
        // the last good cache (stale-while-revalidate) rather than erroring.
        try {
            $data = $this->cfbd->fetchRecruits($apiKey, $year, $team, $maxRecruits);
            $data = $this->photos->enrich($data, $year);

            Cache::put($cacheKey, ['data' => $data, 'fetched_at' => time()], self::HARD_RETENTION_SECONDS);

            return ['data' => $data, 'year' => (int) $year, 'error' => null];
        } catch (\RuntimeException $e) {
            // Stable CFBD error code. Serve stale data if we have any.
            if ($haveCache) {
                return ['data' => $cached['data'], 'year' => (int) $year, 'error' => null];
            }

            return ['data' => [], 'year' => (int) $year, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('[recruiting] RecruitData: '.$e->getMessage(), ['exception' => $e->getMessage()]);
            if ($haveCache) {
                return ['data' => $cached['data'], 'year' => (int) $year, 'error' => null];
            }

            return ['data' => [], 'year' => (int) $year, 'error' => 'unexpected_error'];
        }
    }

    /** Warm the cache for the current settings (used by the scheduled command). */
    public function warm(): array
    {
        return $this->get(forceRefresh: true);
    }
}
