<?php

namespace Ernestdefoe\Recruiting;

use Ernestdefoe\Recruiting\Service\RecruitData;
use Illuminate\Console\Command;

/**
 * Warms the recruiting cache by re-fetching the current class from CFBD (and
 * re-enriching with On3 headshots). Registered to run on the host scheduler
 * roughly every `cache_minutes`, and can also be run by hand:
 * `php artisan convoro:recruiting-refresh`.
 *
 * Keeping the cache warm out-of-band means real page visits always read fresh
 * data without ever paying the CFBD round-trip on the request thread.
 */
class RefreshCommand extends Command
{
    protected $signature = 'convoro:recruiting-refresh';

    protected $description = 'Refresh the cached CFBD recruiting rankings';

    public function handle(RecruitData $data): int
    {
        $res = $data->warm();

        if (($res['error'] ?? null) !== null) {
            $this->warn('Recruiting refresh: '.$res['error']);

            return self::SUCCESS;
        }

        $this->info('Recruiting refresh: cached '.count($res['data']).' recruit(s) for '.$res['year'].'.');

        return self::SUCCESS;
    }
}
