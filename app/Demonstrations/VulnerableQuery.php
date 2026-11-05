<?php

namespace App\Demonstrations;

/**
 * SQL text built by inserting user input directly into a fixed template.
 *
 * THIS IS THE INTENTIONALLY VULNERABLE CONSTRUCTION. It exists only so the
 * demonstrations can show why it is unsafe; it must only ever be executed by
 * VulnerableQueryExecutor on an isolated `lab` connection.
 *
 * The query is kept as segments so the interface can highlight exactly which
 * characters came from the user.
 */
final class VulnerableQuery
{
    /**
     * @param  list<array{text: string, input: bool}>  $segments
     */
    private function __construct(public readonly array $segments) {}

    /**
     * Build the query from a template containing `{field}` placeholders.
     * Each placeholder is replaced by the raw input value, with no escaping.
     *
     * @param  array<string, string>  $input
     */
    public static function fromTemplate(string $template, array $input): self
    {
        $parts = preg_split('/\{([a-z_]+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = [];

        foreach ($parts as $index => $part) {
            $isPlaceholder = $index % 2 === 1;

            if ($isPlaceholder) {
                $segments[] = ['text' => $input[$part] ?? '', 'input' => true];
            } elseif ($part !== '') {
                $segments[] = ['text' => $part, 'input' => false];
            }
        }

        return new self($segments);
    }

    public function sql(): string
    {
        return implode('', array_column($this->segments, 'text'));
    }
}
