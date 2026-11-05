<?php

namespace App\Models\Marketplace;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A marketplace store (table shared with the legacy application). No casts and no automatic timestamps on purpose:
 * attributes keep exactly the values the database returns, so what the pages and JSON endpoints show is unchanged.
 */
final class MarketplaceStore extends Model
{
    use ScopedToCompany;

    protected $table = 'marketplace_stores';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @param  Builder<MarketplaceStore>  $query
     * @return Builder<MarketplaceStore>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /**
     * @param  Builder<MarketplaceStore>  $query
     * @return Builder<MarketplaceStore>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('archived_at'));
    }
}
