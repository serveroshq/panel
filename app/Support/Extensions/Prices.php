<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;
use App\Models\Workspace;

interface Prices
{
    public function plan(Plan $plan, BillingInterval $interval, Currency $currency, ?Workspace $workspace = null): ?int;

    public function mcpPack(Currency $currency): ?int;
}
