<?php

declare(strict_types = 1);

namespace SineMacula\Exporter\Schema;

use Illuminate\Http\Request;

/**
 * Ad-hoc tabular schema mirroring a decoded payload's keys.
 *
 * Built by the negotiation middleware's raw-array fallback so a response that
 * is not backed by an API resource can still be streamed: each column reads its
 * value straight from the decoded row and renders a scalar as-is, JSON-encoding
 * any nested value. Kept internal to that fallback.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class DecodedPayloadSchema extends TabularSchema
{
    /**
     * Create the ad-hoc schema.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     */
    public function __construct(

        // The request the export is built for.
        Request $request,

        /** @var list<\SineMacula\Exporter\Schema\Column> The ad-hoc columns mirroring the decoded payload */
        private readonly array $columns,
    ) {
        parent::__construct($request);
    }

    /**
     * Get the ad-hoc columns mirroring the decoded payload.
     *
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }
}
