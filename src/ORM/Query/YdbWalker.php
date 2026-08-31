<?php

namespace Dudev\YdbDoctrine\ORM\Query;

use Dudev\YdbDoctrine\ORM\Functions\Expression\RandExpression;
use Dudev\YdbDoctrine\ORM\Hack\Setter;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\OrderByItem;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\Subselect;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\SqlWalker;

class YdbWalker extends SqlWalker
{
    private ResultSetMapping $resultSetMapping;
    private Setter $setter;

    /**
     * @param Query<mixed, mixed>   $query
     * @param array<string, mixed>  $queryComponents
     */
    public function __construct(Query $query, ParserResult $parserResult, array $queryComponents)
    {
        $this->resultSetMapping = $parserResult->getResultSetMapping();
        $this->setter = new Setter($this, SqlWalker::class);
        parent::__construct($query, $parserResult, $queryComponents);
    }

    private function getAliasByColumn(string $tableAlias, ?string $columnName): ?string
    {
        foreach ($this->resultSetMapping->fieldMappings as $fieldAlias => $fieldName) {
            if ($fieldName === $columnName) {
                if ($this->resultSetMapping->columnOwnerMap[$fieldAlias] === $tableAlias) {
                    return $fieldAlias;
                }
            }
        }

        if ($fieldAlias = $this->setter->getValue('scalarFields')[$tableAlias][$columnName] ?? null) {
            return $fieldAlias;
        }

        return null;
    }

    public function walkRandExpression(RandExpression $randExpression): string
    {
        $field = $this->getAliasByColumn($randExpression->getTableAlias(), $randExpression->getColumnName());
        if (!$field) {
            throw new \Exception();
        }

        return 'RANDOM(' . $field . ')';
    }

    /**
     * Walks down an OrderByItem AST node, thereby generating the appropriate SQL.
     */
    public function walkOrderByItem(OrderByItem $orderByItem): string
    {
        $type = strtoupper($orderByItem->type);
        $expr = $orderByItem->expression;
        if ($expr instanceof AST\Node) {
            if ($expr instanceof PathExpression) {
                if ($field = $this->getAliasByColumn($expr->identificationVariable, $expr->field)) {
                    $sql = $field;
                } else {
                    $sql = $expr->dispatch($this);
                }
            } else {
                $sql = $expr->dispatch($this);
            }
        } else {
            $sql = $this->walkResultVariable((string) $this->getQueryComponents()[$expr]['token']->value);
        }

        //        $this->orderedColumnsMap[$sql] = $type;
        $map = $this->setter->getValue('orderedColumnsMap');
        $map[$sql] = $type;
        $this->setter->setValue('orderedColumnsMap', $map);

        if ($expr instanceof Subselect) {
            return '(' . $sql . ') ' . $type;
        }

        return $sql . ' ' . $type;
    }
}
