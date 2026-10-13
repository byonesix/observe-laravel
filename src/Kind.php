<?php

namespace Observe\Laravel;

enum Kind: string
{
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';
    case ScheduledTask = 'scheduled_task';
}
