<?php

declare(strict_types=1);

namespace Dudev\YdbDoctrine\ORM;

use Doctrine\ORM\Query as DoctrineQuery;

/**
 * Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER is a public, supported extension
 * point (read by Doctrine\ORM\Query\Parser::parse()) for swapping the SQL walker,
 * so no Parser/Query monkey-patch is needed to plug in YdbWalker. It's wired up
 * as a default query hint in EntityManager::__construct() rather than set here in
 * the constructor, because AbstractQuery::__clone() discards per-instance hints
 * and re-reads them from Configuration::getDefaultQueryHints().
 *
 * @template-covariant TKey
 * @template-covariant TResult
 *
 * @extends DoctrineQuery<TKey, TResult>
 */
class Query extends DoctrineQuery
{
}
