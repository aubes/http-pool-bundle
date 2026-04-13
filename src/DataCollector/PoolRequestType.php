<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\DataCollector;

enum PoolRequestType: string
{
    case Add = 'add';
    case AddOnce = 'addOnce';
    case Fire = 'fire';
}
