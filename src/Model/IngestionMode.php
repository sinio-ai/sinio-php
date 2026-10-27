<?php

declare(strict_types=1);

namespace Sinio\Model;

enum IngestionMode: string
{
    /** Live ingestion: derivation runs and alerts may fire. */
    case Webhook = 'webhook';

    /** History: persisted and derived later, without ever raising an alert. */
    case Backfill = 'backfill';
}
