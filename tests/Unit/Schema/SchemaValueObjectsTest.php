<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\Aggregate;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\EagerLoadPlan;
use SineMacula\Exporter\Schema\Enums\AggregateType;
use SineMacula\Exporter\Schema\Enums\Strictness;
use SineMacula\Exporter\Schema\ExpandAxis;
use SineMacula\Exporter\Schema\ExpandPolicy;
use SineMacula\Exporter\Schema\TabularSchema;
use SineMacula\Exporter\Schema\WarningCollector;

/**
 * Tests the schema value objects and the tabular schema defaults.
 *
 * The aggregate marker, the expand policy and its resolved axis are immutable
 * records the engine and source read; the warning collector is the lenient-mode
 * bag. A tabular schema that overrides nothing but its columns must expose the
 * documented defaults (no relations, no expand axis, no filename, preflight
 * strictness, headings on). These tests pin all of that.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Aggregate::class)]
#[CoversClass(EagerLoadPlan::class)]
#[CoversClass(ExpandAxis::class)]
#[CoversClass(ExpandPolicy::class)]
#[CoversClass(WarningCollector::class)]
#[CoversClass(TabularSchema::class)]
final class SchemaValueObjectsTest extends TestCase
{
    /**
     * It carries the aggregate kind, path and glue.
     *
     * @return void
     */
    public function testAggregateCarriesItsConfiguration(): void
    {
        $sum = new Aggregate(AggregateType::SUM, 'total');

        self::assertSame(AggregateType::SUM, $sum->type);
        self::assertSame('total', $sum->path);
        self::assertSame(', ', $sum->glue);

        $join = new Aggregate(AggregateType::JOIN, glue: ' | ');

        self::assertNull($join->path);
        self::assertSame(' | ', $join->glue);
    }

    /**
     * It carries the relation and empty-parent behaviour for both expand types.
     *
     * @return void
     */
    public function testExpandPolicyAndAxis(): void
    {
        $policy = new ExpandPolicy('orders');

        self::assertSame('orders', $policy->relation);
        self::assertFalse($policy->dropWhenEmpty);

        $axis = new ExpandAxis('orders', dropWhenEmpty: true);

        self::assertSame('orders', $axis->relation);
        self::assertTrue($axis->dropWhenEmpty);

        self::assertFalse((new ExpandAxis('orders'))->dropWhenEmpty, 'A childless parent is kept, not dropped, by default.');
    }

    /**
     * It reports emptiness only when neither aggregate set is populated.
     *
     * @return void
     */
    public function testEagerLoadPlanReportsEmptiness(): void
    {
        self::assertTrue((new EagerLoadPlan)->isEmpty(), 'A plan with no count and no sum is empty.');
        self::assertFalse((new EagerLoadPlan(['orders']))->isEmpty(), 'A count-only plan is not empty.');
        self::assertFalse((new EagerLoadPlan([], [['relation' => 'orders', 'column' => 'total']]))->isEmpty(), 'A sum-only plan is not empty.');
        self::assertFalse((new EagerLoadPlan(['orders'], [['relation' => 'orders', 'column' => 'total']]))->isEmpty(), 'A plan with both is not empty.');
    }

    /**
     * It reports emptiness and collects warnings in order.
     *
     * @return void
     */
    public function testWarningCollector(): void
    {
        $collector = new WarningCollector;

        self::assertTrue($collector->isEmpty());

        $collector->add('first');
        $collector->add('second');

        self::assertFalse($collector->isEmpty());
        self::assertSame(['first', 'second'], $collector->all());
    }

    /**
     * It exposes the documented defaults for an unoverridden schema.
     *
     * @return void
     */
    public function testTabularSchemaDefaults(): void
    {
        $schema = new class (Request::create('/')) extends TabularSchema {
            /**
             * Get the single column for the defaults probe.
             *
             * @return list<\SineMacula\Exporter\Schema\Column>
             */
            #[\Override]
            public function columns(): array
            {
                return [Column::make('id', 'ID')];
            }
        };

        self::assertSame([], $schema->with());
        self::assertNull($schema->expand());
        self::assertNull($schema->filename());
        self::assertSame(Strictness::PREFLIGHT, $schema->strictness());
        self::assertTrue($schema->headings());
        self::assertCount(1, $schema->columns());
    }
}
