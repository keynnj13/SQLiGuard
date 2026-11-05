<?php

namespace Tests\Unit\Demonstrations;

use App\Demonstrations\VulnerableQuery;
use App\Demonstrations\VulnerableQueryExecutor;
use PHPUnit\Framework\TestCase;

class VulnerableQueryTest extends TestCase
{
    public function test_input_is_inserted_verbatim_into_the_template(): void
    {
        $query = VulnerableQuery::fromTemplate("SELECT id FROM t WHERE a = '{a}' AND b = '{b}'", ['a' => "x' OR '1'='1", 'b' => 'y']);

        $this->assertSame("SELECT id FROM t WHERE a = 'x' OR '1'='1' AND b = 'y'", $query->sql());
    }

    public function test_segments_mark_exactly_the_characters_that_came_from_input(): void
    {
        $query = VulnerableQuery::fromTemplate("WHERE a = '{a}'", ['a' => "x'--"]);

        $this->assertSame([
            ['text' => "WHERE a = '", 'input' => false],
            ['text' => "x'--", 'input' => true],
            ['text' => "'", 'input' => false],
        ], $query->segments);
    }

    public function test_placeholder_text_inside_input_is_not_substituted_again(): void
    {
        $query = VulnerableQuery::fromTemplate("a = '{a}' AND b = '{b}'", ['a' => '{b}', 'b' => 'secret']);

        $this->assertSame("a = '{b}' AND b = 'secret'", $query->sql());
    }

    public function test_wrapper_puts_the_generated_sql_on_its_own_lines_so_comments_cannot_reach_the_limit(): void
    {
        $wrapped = VulnerableQueryExecutor::wrap("SELECT id, name FROM t WHERE a = 'x' --'", ['id', 'name']);

        $this->assertSame(
            "SELECT left(demo_result.\"id\"::text, 200) AS \"id\", left(demo_result.\"name\"::text, 200) AS \"name\" FROM (\n"
            ."SELECT id, name FROM t WHERE a = 'x' --'\n"
            .') AS demo_result("id", "name") LIMIT 51',
            $wrapped,
        );
    }
}
