<?php

declare(strict_types=1);

namespace Sinio\Model;

enum LocationLevel: string
{
    case Site = 'site';
    case Zone = 'zone';
    case Point = 'point';
}
