<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

/** Who a product event is about: a person (a Cbox ID user) or an organization. */
enum SubjectType: string
{
    case Contact = 'contact';
    case Company = 'company';
}
