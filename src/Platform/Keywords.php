<?php

namespace Dudev\YdbDoctrine\Platform;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;

/**
 * YQL has no fixed, enumerable reserved-word list - YDB's own docs state this explicitly
 * ("the list of keywords is not fixed and is going to expand as the language develops",
 * see the Lexical structure reference). The Postgres-derived list this used to contain
 * couldn't ever be complete for that reason, and wasn't: it missed real YQL keywords like
 * VARIANT, which broke CREATE TABLE for any column happening to be named that.
 *
 * Rather than chase an unstable target, every identifier is treated as needing quoting -
 * safe and behavior-neutral, since YQL identifiers are always case-sensitive whether
 * quoted or not (nothing is lost by skipping the unquoted-identifier case-folding path).
 */
class Keywords extends KeywordList
{
    public function isKeyword(string $word): bool
    {
        return true;
    }

    protected function getKeywords(): array
    {
        return [];
    }
}
