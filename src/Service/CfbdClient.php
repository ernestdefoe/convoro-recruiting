<?php

namespace Ernestdefoe\Recruiting\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin client over the College Football Data API recruiting/players
 * endpoint. Knows how to authenticate, query, and map CFBD's JSON shape
 * into the internal recruit array the rest of the extension uses.
 *
 * Uses Convoro's standard outbound HTTP path (Laravel `Http` facade with a
 * Bearer token), so it works on shared hosting with no extra dependencies —
 * mirrors how the Picks extension talks to CFBD.
 *
 * Errors are mapped to stable codes the page layer keys off:
 *   cfbd_unreachable, invalid_api_key, cfbd_error_<status>
 */
class CfbdClient
{
    private const BASE_URL = 'https://api.collegefootballdata.com';

    /** Request timeout (seconds) — matches the original client default. */
    private const TIMEOUT = 10;

    /**
     * Fetch + transform a single recruiting class.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException with stable codes — caller maps to error
     *                           states: cfbd_unreachable, invalid_api_key, cfbd_error_<status>
     */
    public function fetchRecruits(string $apiKey, string $year, string $team, int $maxRecruits): array
    {
        $query = ['year' => $year];
        if ($team !== '') {
            $query['team'] = $team;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->get(self::BASE_URL.'/recruiting/players', $query);
        } catch (ConnectionException) {
            throw new \RuntimeException('cfbd_unreachable');
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('invalid_api_key');
        }
        if ($status !== 200) {
            throw new \RuntimeException('cfbd_error_'.$status);
        }

        $raw = $response->json();
        if (! is_array($raw)) {
            return [];
        }

        usort($raw, fn ($a, $b) => ($a['ranking'] ?? 99999) <=> ($b['ranking'] ?? 99999));

        return array_values(array_map(
            fn ($r) => $this->transform($r),
            array_slice($raw, 0, $maxRecruits)
        ));
    }

    /**
     * Project a raw CFBD player object into the extension's stable shape.
     * Defensive against missing fields — CFBD has historically shipped player
     * rows with partial data and we'd rather render "—" than break the page.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function transform(array $r): array
    {
        $heightIn = isset($r['height']) ? (int) $r['height'] : null;
        $height = $heightIn ? sprintf("%d'%d\"", intdiv($heightIn, 12), $heightIn % 12) : null;

        $city = $r['city'] ?? null;
        $state = $r['stateProvince'] ?? null;
        $hometown = implode(', ', array_filter([$city, $state])) ?: null;

        $committedTo = isset($r['committedTo']) && $r['committedTo'] !== ''
            ? (string) $r['committedTo']
            : null;

        return [
            'id' => $r['id'] ?? null,
            'athleteId' => isset($r['athleteId']) ? (int) $r['athleteId'] : null,
            'name' => $r['name'] ?? 'Unknown',
            'position' => isset($r['position']) ? strtoupper((string) $r['position']) : null,
            'height' => $height,
            'weight' => isset($r['weight']) ? ((int) $r['weight']).' lbs' : null,
            'city' => $city,
            'state' => $state,
            'hometown' => $hometown,
            'country' => isset($r['country']) && $r['country'] !== 'USA' ? (string) $r['country'] : null,
            'stars' => isset($r['stars']) ? (int) $r['stars'] : null,
            'rating' => isset($r['rating']) ? round((float) $r['rating'], 4) : null,
            'ranking' => isset($r['ranking']) ? (int) $r['ranking'] : null,
            'status' => $committedTo ? 'committed' : 'undecided',
            'school' => $committedTo,
            'highSchool' => $r['school'] ?? null,
            'recruitType' => $r['recruitType'] ?? 'HighSchool',
            'photoUrl' => null, // filled in by On3PhotoEnricher
        ];
    }
}
