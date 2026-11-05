<?php

namespace App\Demonstrations;

use App\Enums\DemonstrationType;

/**
 * Side-by-side result of running the same input through both modes, plus the
 * student-facing explanation of what happened.
 */
final class Comparison
{
    /**
     * @param  array<string, string>  $input
     */
    public function __construct(
        public readonly DemonstrationType $type,
        public readonly Demonstration $demonstration,
        public readonly array $input,
        public readonly ModeResult $vulnerable,
        public readonly ModeResult $secure,
    ) {}

    /**
     * Whether the input changed what the vulnerable query did.
     *
     * Secure Mode defines the intended behavior, because it always treats the
     * input as data. Vulnerable Mode was altered if it failed to parse or
     * returned different rows. Null when it cannot be determined.
     */
    public function inputAlteredQuery(): ?bool
    {
        if ($this->vulnerable->status === ModeResult::REFUSED) {
            return null;
        }

        if ($this->vulnerable->status === ModeResult::FAILED) {
            return true;
        }

        if (! $this->secure->executed()) {
            return null;
        }

        return $this->sorted($this->vulnerable->rows) !== $this->sorted($this->secure->rows);
    }

    /**
     * @return array{vulnerable: string, secure: string, why: string, principle: string}
     */
    public function explanations(): array
    {
        $altered = $this->inputAlteredQuery();
        $parameterCount = count($this->secure->bindings);

        $vulnerable = match (true) {
            $this->vulnerable->status === ModeResult::REFUSED => $this->vulnerable->message,
            $this->vulnerable->status === ModeResult::FAILED => 'Your input changed the SQL text so much that the database rejected it. '.$this->vulnerable->message.' Even a rejected query shows that the input reaches the SQL code; attackers use such errors to refine their input.',
            $altered === true => $this->demonstration->attackMechanism().' Result: '.$this->vulnerable->summary,
            default => 'The input stayed inside its string literal, so the query kept its intended structure and returned the same rows as Secure Mode. The code is still vulnerable: different input would change the query.',
        };

        $secure = $this->secure->executed()
            ? "The SQL text was fixed before your input arrived. The values were sent separately as {$parameterCount} bound parameter(s), and the database treated each one purely as data, never as SQL. Result: {$this->secure->summary}"
            : 'The secure query could not run. '.$this->secure->message;

        $why = match ($altered) {
            true => 'Both modes received exactly the same input. In Vulnerable Mode the input was concatenated into the SQL code, so its quotes and keywords changed the query\'s structure. In Secure Mode the query structure was compiled first and the input could only ever fill a value slot.',
            false => 'With this input both modes behave the same, because it contains nothing that changes SQL syntax. Try an attack sample: only Vulnerable Mode lets the input change the query.',
            null => 'Only Secure Mode ran, so the two behaviors cannot be compared here. Secure Mode keeps the intended query structure for any input.',
        };

        return [
            'vulnerable' => $vulnerable,
            'secure' => $secure,
            'why' => $why,
            'principle' => 'Never build SQL by concatenating user input. Use parameterized queries (prepared statements with bound parameters) so SQL code and data travel separately. Least privilege is a second layer: the restricted lab account limited what even a successful injection could reach.',
        ];
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<array<string, string|null>>
     */
    private function sorted(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));

        return $rows;
    }
}
