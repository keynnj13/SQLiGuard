<?php

namespace App\Enums;

/**
 * Educational payload categories assigned by PayloadClassifier.
 *
 * A heuristic label only: it never decides whether a query runs and says
 * nothing about whether input is safe. The string values will be stored in
 * comparison history, so keep them stable.
 */
enum PayloadCategory: string
{
    case Normal = 'normal';
    case Tautology = 'tautology';
    case Union = 'union';
    case Comment = 'comment';
    case Unknown = 'unknown';

    /**
     * Primary attack categories, highest precedence first.
     *
     * @var list<self>
     */
    public const array PRECEDENCE = [self::Union, self::Tautology, self::Comment];

    /**
     * Human-readable name shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal input',
            self::Tautology => 'Tautology-based injection',
            self::Union => 'UNION-based injection',
            self::Comment => 'Comment-based injection',
            self::Unknown => 'Unknown / unclassified',
        };
    }
}
