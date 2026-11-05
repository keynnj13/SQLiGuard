<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    private const string LAB_WARNING = 'Lab environment warning';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_home_page_renders_the_bootstrap_layout_with_both_modes(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('navbar', false)
            ->assertSee(config('app.name'))
            ->assertSeeInOrder(['Vulnerable Mode', 'Secure Mode']);
    }

    public function test_lab_warning_is_shown_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(self::LAB_WARNING);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLocalEnvironments(): array
    {
        return [
            'testing' => ['testing'],
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_lab_warning_is_not_shown_outside_the_local_environment(string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(self::LAB_WARNING);
    }
}
