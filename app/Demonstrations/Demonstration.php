<?php

namespace App\Demonstrations;

use Illuminate\Database\Query\Builder;

/**
 * One SQL injection demonstration: a fixed, application-authored query
 * expressed twice, once built unsafely and once parameterized.
 *
 * User input may only fill the declared input fields. It can never choose a
 * table, column, schema or statement; those are fixed in the templates.
 */
interface Demonstration
{
    public function title(): string;

    /**
     * The legitimate operation the query is meant to perform.
     */
    public function scenario(): string;

    public function whatItTests(): string;

    public function vulnerableBehavior(): string;

    public function secureBehavior(): string;

    /**
     * Input fields, keyed by name, in display order.
     *
     * @return array<string, array{label: string, help: string, required: bool}>
     */
    public function fields(): array;

    /**
     * Predefined educational inputs, keyed by a URL-safe identifier.
     *
     * @return array<string, array{label: string, kind: 'normal'|'attack', note: string, values: array<string, string>}>
     */
    public function samples(): array;

    /**
     * Result columns returned by both queries, in order.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * The intended query with the input inserted directly into the SQL text.
     *
     * @param  array<string, string>  $input
     */
    public function vulnerableQuery(array $input): VulnerableQuery;

    /**
     * The same intended query with every input value as a bound parameter.
     *
     * @param  array<string, string>  $input
     */
    public function secureQuery(Builder $query, array $input): Builder;

    /**
     * One-sentence outcome for a result set, e.g. "No matching account".
     *
     * @param  list<array<string, string|null>>  $rows
     * @param  array<string, string>  $input
     */
    public function summarize(array $rows, array $input): string;

    /**
     * How an attack input changes the vulnerable query in this demonstration.
     */
    public function attackMechanism(): string;
}
