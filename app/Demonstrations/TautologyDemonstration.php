<?php

namespace App\Demonstrations;

use Illuminate\Database\Query\Builder;

/**
 * Tautology-based injection against a fake login check.
 *
 * Uses only lab.demo_accounts and the boolean lab.demo_password_matches()
 * function. It never touches the application's users table, never creates a
 * Laravel session, and no password value or digest is ever selected.
 */
final class TautologyDemonstration implements Demonstration
{
    public const string TEMPLATE = "SELECT id, username, display_name, account_type FROM lab.demo_accounts WHERE lab.demo_password_matches(id, '{password}') AND username = '{username}' ORDER BY id";

    public function title(): string
    {
        return 'Tautology-based injection';
    }

    public function scenario(): string
    {
        return 'A fake login form looks up the lab account whose password matches and whose username equals the entered username. A correct login returns exactly one account; anything else is refused.';
    }

    public function whatItTests(): string
    {
        return 'Whether input can add an always-true condition (a tautology such as \'1\'=\'1\') to the WHERE clause of a login query.';
    }

    public function vulnerableBehavior(): string
    {
        return 'The username and password are pasted into the SQL text. A quote in the input ends the string early, and the rest of the input becomes SQL, so OR \'1\'=\'1\' makes every row match and the "login" succeeds without a valid password.';
    }

    public function secureBehavior(): string
    {
        return 'The username and password are sent as bound parameters. The database compares each one as a complete value, so a quote or OR is just part of an (incorrect) username or password and no account matches.';
    }

    public function fields(): array
    {
        return [
            'username' => ['label' => 'Username', 'help' => 'Fake lab usernames: demo.admin, demo.analyst, demo.student, demo.guest.', 'required' => true],
            'password' => ['label' => 'Password', 'help' => 'The fake password for demo.analyst is Analyst-Lab-2026.', 'required' => false],
        ];
    }

    public function samples(): array
    {
        return [
            'valid-login' => [
                'label' => 'Valid fake login',
                'kind' => 'normal',
                'note' => 'Correct fake credentials: both modes accept the login.',
                'values' => ['username' => 'demo.analyst', 'password' => 'Analyst-Lab-2026'],
            ],
            'wrong-password' => [
                'label' => 'Wrong password',
                'kind' => 'normal',
                'note' => 'Both modes refuse the login.',
                'values' => ['username' => 'demo.analyst', 'password' => 'not-the-password'],
            ],
            'always-true-username' => [
                'label' => "Username: ' OR '1'='1",
                'kind' => 'attack',
                'note' => 'Closes the username string and appends an always-true condition.',
                'values' => ['username' => "' OR '1'='1", 'password' => 'anything'],
            ],
            'always-true-password' => [
                'label' => "Password: x') OR ('1'='1",
                'kind' => 'attack',
                'note' => 'Closes the password function call and ORs in a tautology, logging in as demo.analyst without its password.',
                'values' => ['username' => 'demo.analyst', 'password' => "x') OR ('1'='1"],
            ],
        ];
    }

    public function columns(): array
    {
        return ['id', 'username', 'display_name', 'account_type'];
    }

    public function vulnerableQuery(array $input): VulnerableQuery
    {
        return VulnerableQuery::fromTemplate(self::TEMPLATE, $input);
    }

    public function secureQuery(Builder $query, array $input): Builder
    {
        return $query->from('lab.demo_accounts')
            ->select($this->columns())
            ->whereRaw('lab.demo_password_matches(id, ?)', [$input['password']])
            ->where('username', $input['username'])
            ->orderBy('id');
    }

    public function summarize(array $rows, array $input): string
    {
        $count = count($rows);

        return match (true) {
            $count === 0 => 'No matching account: the fake login is refused.',
            $count === 1 => "Login accepted as fake account \"{$rows[0]['username']}\".",
            default => "{$count} accounts matched. A login check must match at most one account, so the intended filtering was bypassed; the first row (\"{$rows[0]['username']}\") would be logged in.",
        };
    }

    public function attackMechanism(): string
    {
        return 'The quote in the input closed the string literal that was supposed to contain the username (or password). Everything after it, such as OR \'1\'=\'1\', was parsed as SQL. Because AND is evaluated before OR, the always-true condition replaced the password check and the WHERE clause matched accounts without a valid password.';
    }
}
