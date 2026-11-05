<?php

namespace App\Demonstrations;

use Illuminate\Database\Query\Builder;

/**
 * Comment-based injection against a fake document list that should only
 * show a team's public documents.
 */
final class CommentDemonstration implements Demonstration
{
    public const string TEMPLATE = "SELECT id, title, owner_team, visibility FROM lab.demo_documents WHERE owner_team = '{team}' AND visibility = 'public' ORDER BY id";

    public function title(): string
    {
        return 'Comment-based injection';
    }

    public function scenario(): string
    {
        return 'A fake portal lists the public documents of the team the user enters. Private documents must never be listed.';
    }

    public function whatItTests(): string
    {
        return 'Whether input can use SQL comment syntax (--) to cut off the rest of the query, including the condition that hides private documents.';
    }

    public function vulnerableBehavior(): string
    {
        return 'The team name is pasted into the SQL text. Input that closes the quote and adds -- turns the remainder of the line, including AND visibility = \'public\', into a comment, so private documents are returned.';
    }

    public function secureBehavior(): string
    {
        return 'The team name is a bound parameter, and the visibility condition is part of the fixed query with its own parameter. The -- is just two characters in a (non-existent) team name, so the condition stays in force.';
    }

    public function fields(): array
    {
        return [
            'team' => ['label' => 'Team', 'help' => 'Fake teams: team-alpha, team-beta.', 'required' => true],
        ];
    }

    public function samples(): array
    {
        return [
            'team-alpha' => [
                'label' => 'Team: team-alpha',
                'kind' => 'normal',
                'note' => 'Lists Team Alpha\'s public documents in both modes.',
                'values' => ['team' => 'team-alpha'],
            ],
            'team-beta' => [
                'label' => 'Team: team-beta',
                'kind' => 'normal',
                'note' => 'Lists Team Beta\'s public documents in both modes.',
                'values' => ['team' => 'team-beta'],
            ],
            'comment-out-visibility' => [
                'label' => "Team: team-alpha' --",
                'kind' => 'attack',
                'note' => 'Comments out the visibility condition and reveals Team Alpha\'s private documents.',
                'values' => ['team' => "team-alpha' --"],
            ],
        ];
    }

    public function columns(): array
    {
        return ['id', 'title', 'owner_team', 'visibility'];
    }

    public function vulnerableQuery(array $input): VulnerableQuery
    {
        return VulnerableQuery::fromTemplate(self::TEMPLATE, $input);
    }

    public function secureQuery(Builder $query, array $input): Builder
    {
        return $query->from('lab.demo_documents')
            ->select($this->columns())
            ->where('owner_team', $input['team'])
            ->where('visibility', 'public')
            ->orderBy('id');
    }

    public function summarize(array $rows, array $input): string
    {
        $count = count($rows);
        $private = count(array_filter($rows, fn (array $row): bool => $row['visibility'] !== 'public'));

        return match (true) {
            $count === 0 => 'No documents returned.',
            $private === 0 => "{$count} public document(s) returned.",
            default => "{$count} document(s) returned, including {$private} non-public document(s) that the visibility condition should have excluded.",
        };
    }

    public function attackMechanism(): string
    {
        return 'The quote in the input closed the team string, and -- started a comment. PostgreSQL ignores everything after -- until the end of the line, so the rest of the WHERE clause (AND visibility = \'public\') and the ORDER BY were never executed.';
    }
}
