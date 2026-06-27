<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent order model for v3 aggregate and row-expansion tests.
 *
 * Belongs to a user (the has-many parent the count/sum/join aggregates and the
 * single expand axis fold). It is Stringable so a join() aggregate over the
 * relation renders each child to its SKU, exercising the join contract that a
 * child is folded through its scalar/stringable representation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @property int $id
 * @property int $user_id
 * @property int $total
 * @property string $sku
 */
final class Order extends Model implements \Stringable
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'orders';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * Render the order as its SKU, so a join() aggregate folds to the SKU list.
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
            'user_id' => 'integer',
            'total'   => 'integer',
        ];
    }
}
