<?php

declare(strict_types = 1);

namespace Tests\Support\V3\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Authenticatable actor model for the queued-export authorization tests.
 *
 * The queued pipeline serializes an actor by id and class, then re-resolves
 * the real user on the worker to re-check full-set authorization through the
 * gate. This fixture is a minimal Authenticatable the queued tests attribute
 * an export to, so a forbidden actor can be rejected and an audited actor
 * identifier can be asserted on the completion event.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @property int $id
 * @property string $name
 */
final class Actor extends Authenticatable
{
    /** @var bool Whether the model maintains timestamps */
    public $timestamps = false;

    /** @var string|null The backing table */
    protected $table = 'actors';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];
}
