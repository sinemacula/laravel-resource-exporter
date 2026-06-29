<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User model variant that hides its secret attribute from array/JSON output.
 *
 * Backs the field-visibility honesty test: the tabular export reads the raw
 * attribute via data_get and still emits the secret, proving export resolution
 * does not honour the model's $hidden serialisation gating - field gating in an
 * export is the schema author's job, expressed with ->visible().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @property int $id
 * @property string $name
 * @property string|null $secret
 */
final class HiddenAttributeUser extends Model
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'users';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /** @var array<string> The attributes hidden from array/JSON output */
    protected $hidden = ['secret'];
}
