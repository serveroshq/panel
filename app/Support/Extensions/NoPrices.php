<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Workspace;

final class NoPrices implements Prices
{
    public function plan(Plan $plan, BillingInterval $interval, Currency $currency, ?Workspace $workspace = null): ?int
    {
        return null;
    }

    public function mcpPack(Currency $currency): ?int
    {
        return null;
    }
}
