<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventImage extends Model
{
    protected $guarded = [];

    protected $appends = ['url'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Root-relative URL for the locally-stored image (e.g. /storage/events/x.svg).
     * Relative so it works regardless of host/port (localhost vs 127.0.0.1:8000).
     */
    public function getUrlAttribute(): string
    {
        return '/storage/'.ltrim($this->path, '/');
    }
}
