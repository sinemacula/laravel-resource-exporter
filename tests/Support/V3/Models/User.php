<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent user model for v3 source, query and negotiation tests.
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
