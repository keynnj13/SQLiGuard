<?php

namespace Tests\Unit\Enums;

use App\Enums\DemonstrationErrorCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DemonstrationErrorCategoryTest extends TestCase
{
    /**
     * @return array<string, array{string, DemonstrationErrorCategory}>
     */
    public static function sqlStates(): array
    {
        return [
            'syntax error 42601' => ['42601', DemonstrationErrorCategory::SyntaxError],
            'insufficient privilege 42501' => ['42501', DemonstrationErrorCategory::PermissionDenied],
            'query canceled 57014' => ['57014', DemonstrationErrorCategory::StatementTimeout],
            'read-only transaction 25006' => ['25006', DemonstrationErrorCategory::ReadOnlyViolation],
            'undefined table 42P01' => ['42P01', DemonstrationErrorCategory::InvalidObjectReference],
            'undefined column 42703' => ['42703', DemonstrationErrorCategory::InvalidObjectReference],
            'undefined function 42883' => ['42883', DemonstrationErrorCategory::InvalidObjectReference],
            'datatype mismatch 42804' => ['42804', DemonstrationErrorCategory::InvalidObjectReference],
            'invalid text representation 22P02' => ['22P02', DemonstrationErrorCategory::InvalidValue],
            'division by zero 22012' => ['22012', DemonstrationErrorCategory::InvalidValue],
            'numeric value out of range 22003' => ['22003', DemonstrationErrorCategory::InvalidValue],
            'other 57xxx (admin shutdown)' => ['57P01', DemonstrationErrorCategory::Unexpected],
            'other 25xxx (in failed transaction)' => ['25P02', DemonstrationErrorCategory::Unexpected],
            'connection failure 08006' => ['08006', DemonstrationErrorCategory::Unexpected],
            'serialization failure 40001' => ['40001', DemonstrationErrorCategory::Unexpected],
            'internal error XX000' => ['XX000', DemonstrationErrorCategory::Unexpected],
            'PDO general error HY000' => ['HY000', DemonstrationErrorCategory::Unexpected],
            'no code' => ['', DemonstrationErrorCategory::Unexpected],
        ];
    }

    #[DataProvider('sqlStates')]
    public function test_sqlstate_maps_to_its_category(string $sqlState, DemonstrationErrorCategory $expected): void
    {
        $this->assertSame($expected, DemonstrationErrorCategory::fromSqlState($sqlState));
    }

    /**
     * The exact messages shown before categories existed (Phase 3), copied literally.
     *
     * @return array<string, array{DemonstrationErrorCategory, string}>
     */
    public static function phaseThreeMessages(): array
    {
        return [
            'syntax' => [DemonstrationErrorCategory::SyntaxError, 'Syntax error: the input changed the SQL text so that it is no longer a single valid statement, so the database rejected it.'],
            'permission' => [DemonstrationErrorCategory::PermissionDenied, 'Permission denied: the restricted lab account is not allowed to read that object. Least privilege stopped this query.'],
            'timeout' => [DemonstrationErrorCategory::StatementTimeout, 'The query was cancelled by the 5-second statement timeout.'],
            'read-only' => [DemonstrationErrorCategory::ReadOnlyViolation, 'The read-only demonstration transaction refused an attempt to change data.'],
            'object reference' => [DemonstrationErrorCategory::InvalidObjectReference, 'The query referred to a table, column, function or type that the database could not use, or the UNION parts did not have matching columns.'],
            'value' => [DemonstrationErrorCategory::InvalidValue, 'The input produced a value the database could not convert or compare.'],
            'unexpected' => [DemonstrationErrorCategory::Unexpected, 'The demonstration database could not run this query. Check that the local PostgreSQL lab is running and seeded.'],
        ];
    }

    #[DataProvider('phaseThreeMessages')]
    public function test_safe_messages_are_unchanged_and_reveal_no_sqlstate(DemonstrationErrorCategory $category, string $expected): void
    {
        $this->assertSame($expected, $category->safeMessage());
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE|\b\d{2}[0-9A-Z]{3}\b|\b[A-Z]{2}\d{3}\b/', $category->safeMessage());
    }

    public function test_stored_values_are_stable(): void
    {
        $this->assertSame(
            ['syntax_error', 'permission_denied', 'statement_timeout', 'read_only_violation', 'invalid_object_reference', 'invalid_value', 'unexpected'],
            array_column(DemonstrationErrorCategory::cases(), 'value'),
        );
    }
}
