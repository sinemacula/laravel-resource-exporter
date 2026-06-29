<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent user model for source, query and negotiation tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property int $score
 * @property bool $active
 * @property string|null $secret
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Database\Eloquent\Collection<int, \Tests\Support\Models\Order> $orders
 */
final class User extends Model
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'users';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * The user's orders (the has-many relation the aggregate and expand axes
     * fold).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope the query to users scoring at least the given threshold.
     *
     * Exercised by the queued-export specification's named-scope replay.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Support\Models\User>  $query
     * @param  int  $minimum
     * @return void
     */
    public function scopeScoreAtLeast(Builder $query, int $minimum): void
    {
        $query->where('score', '>=', $minimum);
    }

    /**
     * Scope the query to high-scoring users, taking no arguments.
     *
     * Exercised by the queued-export specification's argument-less named-scope
     * replay, which routes through the single-element [$name] scopes() form.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Support\Models\User>  $query
     * @return void
     */
    public function scopeHighScorers(Builder $query): void
    {
        $query->where('score', '>=', 8);
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
            'active'     => 'boolean',
            'score'      => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
