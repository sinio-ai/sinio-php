<?php

declare(strict_types=1);

namespace Sinio\Model;

enum EntityKind: string
{
    case Device = 'device';
    case Subject = 'subject';
    case Asset = 'asset';
    case Actor = 'actor';
    case Credential = 'credential';
}
