<?php

declare(strict_types=1);

namespace Sinio\Model;

/** How a credential relates to the subject it is assigned to (`relation` of `POST /v1/assignments`). */
enum AssignmentRelation: string
{
    case AssignedTo = 'assigned_to';
}
