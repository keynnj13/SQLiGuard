<?php

namespace App\Demonstrations;

use Illuminate\Database\Query\Builder;

/**
 * UNION-based injection against a fake product catalogue.
 *
 * The designated "hidden" records live in lab.demo_internal_projects, a fake
 * table the lab account may read. Application tables are not reachable: the
 * lab account has no USAGE on the `public` schema.
 */
final class UnionDemonstration implements Demonstration
{
    public const string TEMPLATE = "SELECT id, name, category, price FROM lab.demo_products WHERE is_published = true AND category = '{category}' ORDER BY id";

    public function title(): string
    {
        return 'UNION-based injection';
    }

    public function scenario(): string
    {
        return 'A fake shop lists its published products in the category the user enters.';
    }

    public function whatItTests(): string
    {
        return 'Whether input can append a UNION SELECT to the catalogue query and pull rows from a different table into the product list.';
    }

    public function vulnerableBehavior(): string
    {
        return 'The category is pasted into the SQL text. Input that closes the quote and adds UNION SELECT … FROM another table combines that table\'s rows with the product results, as long as the column count and types match.';
    }

    public function secureBehavior(): string
    {
        return 'The category is a bound parameter. The whole input, including the word UNION, is compared as one category name, so no product matches and no other table is read.';
    }

    public function fields(): array
    {
        return [
            'category' => ['label' => 'Category', 'help' => 'Fake categories: stationery, electronics.', 'required' => true],
        ];
    }

    public function samples(): array
    {
        return [
            'stationery' => [
                'label' => 'Category: stationery',
                'kind' => 'normal',
                'note' => 'Lists published stationery products in both modes.',
                'values' => ['category' => 'stationery'],
            ],
            'electronics' => [
                'label' => 'Category: electronics',
                'kind' => 'normal',
                'note' => 'Lists published electronics products in both modes.',
                'values' => ['category' => 'electronics'],
            ],
            'union-internal-projects' => [
                'label' => 'UNION with fake internal projects',
                'kind' => 'attack',
                'note' => 'Appends rows from the fake lab.demo_internal_projects table to the product list.',
                'values' => ['category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects WHERE 'a'='a"],
            ],
            'union-application-users' => [
                'label' => 'UNION with application users (blocked)',
                'kind' => 'attack',
                'note' => 'Tries to read the real application accounts. The restricted lab account has no access to that schema, so the database refuses.',
                'values' => ['category' => "none' UNION SELECT id, name, email, 0 FROM public.users WHERE 'a'='a"],
            ],
        ];
    }

    public function columns(): array
    {
        return ['id', 'name', 'category', 'price'];
    }

    public function vulnerableQuery(array $input): VulnerableQuery
    {
        return VulnerableQuery::fromTemplate(self::TEMPLATE, $input);
    }

    public function secureQuery(Builder $query, array $input): Builder
    {
        return $query->from('lab.demo_products')
            ->select($this->columns())
            ->where('is_published', true)
            ->where('category', $input['category'])
            ->orderBy('id');
    }

    public function summarize(array $rows, array $input): string
    {
        $count = count($rows);
        $foreign = count(array_filter($rows, fn (array $row): bool => $row['category'] !== $input['category']));

        return match (true) {
            $count === 0 => 'No products returned.',
            $foreign === 0 => "{$count} published product(s) in category \"{$input['category']}\".",
            default => "{$count} row(s) returned, of which {$foreign} are not products in the requested category: they were added by an injected UNION from outside the intended query.",
        };
    }

    public function attackMechanism(): string
    {
        return 'The quote in the input closed the category string, and UNION SELECT … appended a second query. Its rows were combined with the (empty) product results because it selected the same number of columns with compatible types. The closing \'a\'=\'a\' absorbed the template\'s own trailing quote so the SQL stayed valid.';
    }
}
