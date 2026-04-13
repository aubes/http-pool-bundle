<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle;

enum ErrorStrategy
{
    /** Errors are collected, flush() continues, use getErrors() to read them. */
    case Collect;

    /** flush() stops on the first error, cancels in-flight requests, and throws. */
    case StopOnFirst;

    /** flush() runs all requests, then throws an aggregate exception if any errors occurred. */
    case ThrowAll;
}
