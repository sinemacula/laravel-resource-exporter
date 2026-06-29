<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent user fixture for query-source benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property bool $is_active
 * @property float $balance
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class BenchmarkUser extends Model
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'benchmark_users';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * Get the user's benchmark orders.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Benchmarks\Support\BenchmarkOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(BenchmarkOrder::class, 'user_id');
    }

    /**
     * Get the attribute casts for the model.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'balance'    => 'float',
            'created_at' => 'datetime',
        ];
    }
}
