<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

/**
 * What kind of event it is to Engage. Product usage is `behavior`; `communication` is
 * reserved for mail the app sent itself, around Engage (the mail driver's fallback).
 */
enum Category: string
{
    case Behavior = 'behavior';
    case Communication = 'communication';
}
