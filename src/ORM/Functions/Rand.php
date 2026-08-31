<?php

namespace Dudev\YdbDoctrine\ORM\Functions;

use Dudev\YdbDoctrine\ORM\Functions\Expression\RandExpression;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

class Rand extends FunctionNode
{
    private RandExpression $randExpression;

    private function makeRandExpression(Parser $parser): RandExpression
    {
        $lexer = $parser->getLexer();
        $token = $lexer->lookahead ?? throw new QueryException('Unexpected end of DQL: expected RAND(...)');
        if ('RAND' !== $token->value) {
            throw new QueryException();
        }
        $parser->match($token->type ?? throw new QueryException('Unexpected token with no type'));
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $tableAlias = $this->currentTokenValue($lexer);
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_DOT);
        $columnName = $this->currentTokenValue($lexer);
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);

        return new RandExpression($tableAlias, $columnName);
    }

    private function currentTokenValue(Lexer $lexer): string
    {
        return ($lexer->lookahead ?? throw new QueryException('Unexpected end of DQL'))->value;
    }

    public function parse(Parser $parser): void
    {
        $this->randExpression = $this->makeRandExpression($parser);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return $this->randExpression->dispatch($sqlWalker);
    }
}
