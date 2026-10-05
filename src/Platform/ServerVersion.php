<?php

namespace Dudev\YdbDoctrine\Platform;

/** What `SELECT version()` (or DBAL's `serverVersion` param) says, reduced to the major.minor that capabilities depend on. */
final class ServerVersion
{
    private function __construct(
        public readonly string $raw,
        public readonly int $major,
        public readonly int $minor,
    ) {
    }

    /**
     * Seen live: 24.4.4.12 and 26.3.1.17 (local images), stable-25-4-1 and stable-26-3-1-17 (managed, same
     * build as 26.3.1.17), main (trunk). A branch name without a number is an unreleased build: newer than any release.
     */
    public static function tryParse(string $version): ?self
    {
        if (1 === preg_match('/^(?:stable-)?(\d+)[.-](\d+)/', $version, $parts)) {
            return new self($version, (int) $parts[1], (int) $parts[2]);
        }

        $unreleased = in_array($version, ['main', 'trunk', 'edge', 'nightly'], true);

        return $unreleased ? new self($version, PHP_INT_MAX, 0) : null;
    }

    public function isAtLeast(int $major, int $minor = 0): bool
    {
        return $this->major > $major || ($this->major === $major && $this->minor >= $minor);
    }
}
