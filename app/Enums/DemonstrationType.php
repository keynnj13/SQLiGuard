<?php

namespace App\Enums;

use App\Demonstrations\CommentDemonstration;
use App\Demonstrations\Demonstration;
use App\Demonstrations\TautologyDemonstration;
use App\Demonstrations\UnionDemonstration;

/**
 * The three approved demonstrations. The values are used in URLs
 * (`/demonstrations/{demonstration}`); any other value is a 404.
 */
enum DemonstrationType: string
{
    case Tautology = 'tautology';
    case Union = 'union';
    case Comment = 'comment';

    public function demonstration(): Demonstration
    {
        return match ($this) {
            self::Tautology => new TautologyDemonstration,
            self::Union => new UnionDemonstration,
            self::Comment => new CommentDemonstration,
        };
    }
}
