<?php

namespace App\Models\Concerns;

trait UsesAnalyticsConnection
{
    public function getConnectionName(): ?string
    {
        return config('tidemark.analytics_connection') ?: parent::getConnectionName();
    }
}
