<?php

declare(strict_types = 1);

namespace Benchmarks\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent order fixture for query-source aggregate benchmarks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quantity
 * @property float $total
 * @property string $sku
 * @property string $purchased_at
 */
final class BenchmarkOrder extends Model implements \Stringable
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'benchmark_orders';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * Render an order for join aggregate benchmarks.
     *
     * @return string
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->sku;
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
            'user_id'  => 'integer',
            'quantity' => 'integer',
            'total'    => 'float',
        ];
    }
}
