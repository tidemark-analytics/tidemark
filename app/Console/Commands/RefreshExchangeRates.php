<?php

namespace App\Console\Commands;

use App\Analytics\Support\ExchangeRates;
use App\Models\Goal;
use Illuminate\Console\Command;

class RefreshExchangeRates extends Command
{
    protected $signature = 'tidemark:refresh-rates {--force : Fetch even when no goal reports revenue}';

    protected $description = 'Fetch the daily exchange rates used to add up revenue sent in different currencies';

    public function handle(): int
    {
        if (! config('tidemark.exchange_rates')) {
            $this->info('Exchange rates are turned off (TIDEMARK_EXCHANGE_RATES=false).');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! Goal::whereNotNull('currency')->exists()) {
            return self::SUCCESS;
        }
        $count = ExchangeRates::refresh();
        $count ? $this->info("Stored {$count} exchange rates.") : $this->error('Could not read the exchange rates.');

        return $count ? self::SUCCESS : self::FAILURE;
    }
}
