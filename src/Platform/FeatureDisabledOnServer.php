<?php

namespace Dudev\YdbDoctrine\Platform;

/** The server understood the statement but has the feature switched off (a feature flag): the cluster config has to change. */
final class FeatureDisabledOnServer extends \RuntimeException
{
    /** Server message => the flag that enables it, as `--enable-feature-flag` / the cluster config spell it. */
    private const FLAGS = [
        'Adding columns with defaults is disabled' => 'enable_add_colums_with_defaults',
        'Adding a unique index to an existing table is disabled' => 'enable_add_unique_index',
    ];

    public static function tryFrom(string $sql, \Throwable $serverError): ?self
    {
        foreach (self::FLAGS as $message => $flag) {
            if (str_contains($serverError->getMessage(), $message)) {
                return new self(
                    "$sql\n The server has this feature switched off ($message). "
                    . "Turn on the `$flag` feature flag in the cluster config.",
                    previous: $serverError,
                );
            }
        }

        return null;
    }
}
