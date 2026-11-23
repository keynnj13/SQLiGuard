<?php

namespace App\Enums;

/**
 * Structured category of a failed demonstration query.
 *
 * The single mapping from PostgreSQL SQLSTATE codes to a category and to the
 * fixed, safe message shown to users. The SQLSTATE itself is never exposed
 * or stored, and no message ever contains database details or input. The
 * string values will be stored in comparison history, so keep them stable.
 */
enum DemonstrationErrorCategory: string
{
    case SyntaxError = 'syntax_error';
    case PermissionDenied = 'permission_denied';
    case StatementTimeout = 'statement_timeout';
    case ReadOnlyViolation = 'read_only_violation';
    case InvalidObjectReference = 'invalid_object_reference';
    case InvalidValue = 'invalid_value';
    case Unexpected = 'unexpected';

    /**
     * Map a SQLSTATE code. Specific codes are checked before their classes
     * (42601 and 42501 before other 42xxx); anything unrecognized is Unexpected.
     */
    public static function fromSqlState(string $sqlState): self
    {
        return match (true) {
            $sqlState === '42601' => self::SyntaxError,
            $sqlState === '42501' => self::PermissionDenied,
            $sqlState === '57014' => self::StatementTimeout,
            $sqlState === '25006' => self::ReadOnlyViolation,
            str_starts_with($sqlState, '42') => self::InvalidObjectReference,
            str_starts_with($sqlState, '22') => self::InvalidValue,
            default => self::Unexpected,
        };
    }

    /**
     * Fixed, educational message shown to users for this category.
     */
    public function safeMessage(): string
    {
        return match ($this) {
            self::SyntaxError => 'Syntax error: the input changed the SQL text so that it is no longer a single valid statement, so the database rejected it.',
            self::PermissionDenied => 'Permission denied: the restricted lab account is not allowed to read that object. Least privilege stopped this query.',
            self::StatementTimeout => 'The query was cancelled by the 5-second statement timeout.',
            self::ReadOnlyViolation => 'The read-only demonstration transaction refused an attempt to change data.',
            self::InvalidObjectReference => 'The query referred to a table, column, function or type that the database could not use, or the UNION parts did not have matching columns.',
            self::InvalidValue => 'The input produced a value the database could not convert or compare.',
            self::Unexpected => 'The demonstration database could not run this query. Check that the local PostgreSQL lab is running and seeded.',
        };
    }
}
