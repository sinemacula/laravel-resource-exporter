<?php

declare(strict_types = 1);

namespace Tests\Unit\Writers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Exporter\Schema\CellValue;
use SineMacula\Exporter\Schema\Enums\CellType;
use SineMacula\Exporter\Writers\CellRenderer;

/**
 * Tests for the shared cell value coercion the tabular writers delegate to.
 *
 * The CSV and XLSX writers render byte-identical scalar coercions; this proves
 * the extracted collaborator preserves each one - numeric pass-through, the
 * non-numeric fallbacks, the Yes/No boolean label split, and the Stringable
 * coercion.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CellRenderer::class)]
final class CellRendererTest extends TestCase
{
    /**
     * It passes native integers through and coerces numeric strings.
     *
     * @return void
     */
    public function testRenderIntCoercesNumericValuesAndFallsBackToZero(): void
    {
        $renderer = new CellRenderer;

        self::assertSame(42, $renderer->renderInt(42));
        self::assertSame(7, $renderer->renderInt('7'));
        self::assertSame(0, $renderer->renderInt('not-a-number'));
    }

    /**
     * It passes native floats through and coerces numeric strings.
     *
     * @return void
     */
    public function testRenderFloatCoercesNumericValuesAndFallsBackToZero(): void
    {
        $renderer = new CellRenderer;

        self::assertSame(1.5, $renderer->renderFloat(1.5));
        self::assertSame(2.25, $renderer->renderFloat('2.25'));
        self::assertSame(0.0, $renderer->renderFloat('not-a-number'));
    }

    /**
     * It renders the default and custom boolean labels.
     *
     * @return void
     */
    public function testRenderBooleanUsesTheConfiguredLabels(): void
    {
        $renderer = new CellRenderer;

        self::assertSame('Yes', $renderer->renderBoolean(new CellValue(true, CellType::BOOLEAN)));
        self::assertSame('No', $renderer->renderBoolean(new CellValue(false, CellType::BOOLEAN)));
        self::assertSame('On', $renderer->renderBoolean(new CellValue(true, CellType::BOOLEAN, 'On|Off')));
        self::assertSame('Off', $renderer->renderBoolean(new CellValue(false, CellType::BOOLEAN, 'On|Off')));
    }

    /**
     * It stringifies scalars and Stringables, blanking everything else.
     *
     * @return void
     */
    public function testRenderStringCoercesScalarsAndStringables(): void
    {
        $renderer = new CellRenderer;

        $stringable = new class implements \Stringable {
            /**
             * Render the stringable value.
             *
             * @return string
             */
            #[\Override]
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        self::assertSame('plain', $renderer->renderString('plain'));
        self::assertSame('9', $renderer->renderString(9));
        self::assertSame('stringable', $renderer->renderString($stringable));
        self::assertSame('', $renderer->renderString(['array']));
    }
}
