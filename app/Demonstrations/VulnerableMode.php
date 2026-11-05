<?php

namespace App\Demonstrations;

/**
 * Single source of truth for whether Vulnerable Mode may run.
 *
 * Vulnerable execution is only ever allowed when the application runs in the
 * `local` environment. This is enforced on the server (DemonstrationRunner and
 * VulnerableQueryExecutor), and the lab warning banner uses the same check.
 */
final class VulnerableMode
{
    public static function isAvailable(): bool
    {
        return app()->environment('local');
    }
}
