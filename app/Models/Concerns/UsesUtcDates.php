<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Date;

trait UsesUtcDates
{
    public function freshTimestamp()
    {
        return Date::now('UTC');
    }

    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $value)) {
            return Date::parse($value, 'UTC');
        }

        return parent::asDateTime($value);
    }
}
