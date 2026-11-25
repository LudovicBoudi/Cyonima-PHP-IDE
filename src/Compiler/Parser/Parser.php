<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Parser;

use Cyonima\Compiler\Ast\AppendExpr;
use Cyonima\Compiler\Ast\ArrayExpr;
use Cyonima\Compiler\Ast\AssignExpr;
use Cyonima\Compiler\Ast\BinaryExpr;
use Cyonima\Compiler\Ast\Block;
use Cyonima\Compiler\Ast\CallExpr;
use Cyonima\Compiler\Ast\EchoStmt;
use Cyonima\Compiler\Ast\Expr;
use Cyonima\Compiler\Ast\ExpressionStmt;
use Cyonima\Compiler\Ast\ForStmt;
use Cyonima\Compiler\Ast\ForeachStmt;
use Cyonima\Compiler\Ast\FunctionDecl;
use Cyonima\Compiler\Ast\IfStmt;
use Cyonima\Compiler\Ast\IndexAssignExpr;
use Cyonima\Compiler\Ast\IndexExpr;
use Cyonima\Compiler\Ast\Literal;
use Cyonima\Compiler\Ast\Param;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ast\ReturnStmt;
use Cyonima\Compiler\Ast\Stmt;
use Cyonima\Compiler\Ast\UnaryExpr;
use Cyonima\Compiler\Ast\VariableExpr;
use Cyonima\Compiler\Ast\WhileStmt;
use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Lexer\Token;
use Cyonima\Compiler\Lexer\TokenType;

/**
 * Parser descendant récursif du sous-ensemble PHP.
 *
 * La grammaire est volontairement fermée : les constructions reconnues par le
 * lexer mais hors périmètre produisent une erreur de compilation explicite.
 */
final class Parser
{
    private int $current = 0;

    /** @param list<Token> $tokens */
    public function __construct(private readonly array $tokens)
    {
    }

    public function parse(): Program
    {
        $functions = [];
        $statements = [];

        while (!$this->check(TokenType::Eof)) {
            if ($this->match(TokenType::Declare)) {
                $this->parseDeclareRemainder();
                continue;
            }

            if ($this->match(TokenType::Function)) {
                $functions[] = $this->parseFunctionAfterKeyword($this->previous());
                continue;
            }

            $statements[] = $this->parseStatement();
        }

        return new Program($functions, $statements);
    }

    private function parseDeclareRemainder(): void
    {
        $line = $this->previous()->line;
        $this->consume(TokenType::LeftParen, 'attendu : ( après declare');
        $name = $this->consume(TokenType::Identifier, 'attendu : nom de directive après declare(');
        $this->consume(TokenType::Assign, 'attendu : = dans declare()');
        $value = $this->consume(TokenType::Integer, 'attendu : valeur entière dans declare()');
        $this->consume(TokenType::RightParen, 'attendu : ) après declare()');
        $this->consume(TokenType::Semicolon, 'attendu : ; après declare()');

        if (strtolower((string) $name->literal) !== 'strict_types' || $value->literal !== 1) {
            throw new CompilerException(
                "ligne {$line} : seule la directive declare(strict_types=1) est supportée",
            );
        }
    }

    private function parseFunctionAfterKeyword(Token $keyword): FunctionDecl
    {
        $name = $this->consume(TokenType::Identifier, 'attendu : nom de fonction après function');
        $this->consume(TokenType::LeftParen, 'attendu : ( après le nom de fonction');
        $params = [];

        if (!$this->check(TokenType::RightParen)) {
            do {
                $type = $this->parseType();
                $variable = $this->consume(TokenType::Variable, 'attendu : paramètre $nom après son type');
                $params[] = new Param((string) $variable->literal, $type, $variable->line);
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(TokenType::RightParen, 'attendu : ) après les paramètres');
        $returnType = 'void';

        if ($this->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LeftBrace, 'attendu : { pour le corps de la fonction');
        $body = $this->parseBlockAfterBrace($keyword->line);

        return new FunctionDecl(
            (string) $name->literal,
            $params,
            $returnType,
            $body,
            $keyword->line,
        );
    }

    private function parseStatement(): Stmt
    {
        $token = $this->peek();

        if ($this->match(TokenType::LeftBrace)) {
            return $this->parseBlockAfterBrace($token->line);
        }

        if ($this->match(TokenType::If)) {
            return $this->parseIfAfterKeyword($token);
        }

        if ($this->match(TokenType::While)) {
            return $this->parseWhileAfterKeyword($token);
        }

        if ($this->match(TokenType::For)) {
            return $this->parseForAfterKeyword($token);
        }

        if ($this->match(TokenType::ForEach)) {
            return $this->parseForeachAfterKeyword($token);
        }

        if ($this->match(TokenType::Return)) {
            return $this->parseReturnAfterKeyword($token);
        }

        if ($this->match(TokenType::Echo)) {
            return $this->parseEchoAfterKeyword($token);
        }

        if ($this->match(TokenType::Function)) {
            throw new CompilerException(
                "ligne {$token->line} : fonction imbriquée non supportée",
            );
        }

        return $this->parseExpressionStatement();
    }

    private function parseBlockAfterBrace(int $line): Block
    {
        $statements = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::Eof)) {
            $statements[] = $this->parseStatement();
        }

        $this->consume(TokenType::RightBrace, 'attendu : } pour fermer le bloc');

        return new Block($statements, $line);
    }

    private function parseIfAfterKeyword(Token $keyword): IfStmt
    {
        $condition = $this->parseParenthesizedExpression();
        $then = $this->parseStatement();
        $root = new IfStmt($condition, $then, $keyword->line);
        $current = $root;

        while ($this->match(TokenType::ElseIf)) {
            $elseIf = $this->previous();
            $condition = $this->parseParenthesizedExpression();
            $body = $this->parseStatement();
            $next = new IfStmt($condition, $body, $elseIf->line);
            $current->else = $next;
            $current = $next;
        }

        if ($this->match(TokenType::Else)) {
            $current->else = $this->parseStatement();
        }

        return $root;
    }

    private function parseWhileAfterKeyword(Token $keyword): WhileStmt
    {
        $condition = $this->parseParenthesizedExpression();
        $body = $this->parseStatement();

        return new WhileStmt($condition, $body, $keyword->line);
    }

    private function parseForAfterKeyword(Token $keyword): ForStmt
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après for');

        $init = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
        $this->consume(TokenType::Semicolon, 'attendu : ; après l’initialisation de for');

        $condition = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
        $this->consume(TokenType::Semicolon, 'attendu : ; après la condition de for');

        $step = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
        $this->consume(TokenType::RightParen, 'attendu : ) après for');

        $body = $this->parseStatement();

        return new ForStmt($init, $condition, $step, $body, $keyword->line);
    }

    private function parseForeachAfterKeyword(Token $keyword): ForeachStmt
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après foreach');
        $iterable = $this->parseExpression();
        $this->consume(TokenType::As, 'attendu : as dans foreach');
        $first = $this->consume(TokenType::Variable, 'attendu : variable après foreach ... as');
        $keyName = null;
        $value = $first;

        if ($this->match(TokenType::Arrow)) {
            $keyName = (string) $first->literal;
            $value = $this->consume(TokenType::Variable, 'attendu : variable de valeur après =>');
        }

        $this->consume(TokenType::RightParen, 'attendu : ) après foreach');
        $body = $this->parseStatement();

        return new ForeachStmt(
            $iterable,
            $keyName,
            (string) $value->literal,
            $body,
            $keyword->line,
        );
    }

    private function parseReturnAfterKeyword(Token $keyword): ReturnStmt
    {
        if ($this->match(TokenType::Semicolon)) {
            return new ReturnStmt(null, $keyword->line);
        }

        $value = $this->parseExpression();
        $this->consume(TokenType::Semicolon, 'attendu : ; après return');

        return new ReturnStmt($value, $keyword->line);
    }

    private function parseEchoAfterKeyword(Token $keyword): EchoStmt
    {
        $expressions = [$this->parseExpression()];

        while ($this->match(TokenType::Comma)) {
            $expressions[] = $this->parseExpression();
        }

        $this->consume(TokenType::Semicolon, 'attendu : ; après echo');

        return new EchoStmt($expressions, $keyword->line);
    }

    private function parseExpressionStatement(): ExpressionStmt
    {
        $line = $this->peek()->line;
        $expression = $this->parseExpression();
        $this->consume(TokenType::Semicolon, 'attendu : ; après l’expression');

        return new ExpressionStmt($expression, $line);
    }

    private function parseParenthesizedExpression(): Expr
    {
        $this->consume(TokenType::LeftParen, 'attendu : (');
        $expression = $this->parseExpression();
        $this->consume(TokenType::RightParen, 'attendu : )');

        return $expression;
    }

    private function parseExpression(): Expr
    {
        return $this->parseAssignment();
    }

    private function parseAssignment(): Expr
    {
        $expression = $this->parseOr();

        if ($this->match(TokenType::Assign)) {
            $operator = $this->previous();
            $value = $this->parseAssignment();

            if ($expression instanceof VariableExpr) {
                return new AssignExpr($expression->name, $value, $operator->line);
            }

            if ($expression instanceof IndexExpr) {
                if ($expression->index === null) {
                    return new AppendExpr($expression->array, $value, $operator->line);
                }

                return new IndexAssignExpr(
                    $expression->array,
                    $expression->index,
                    $value,
                    $operator->line,
                );
            }

            throw new CompilerException(
                "ligne {$operator->line} : cible d’affectation non supportée",
            );
        }

        return $expression;
    }

    private function parseOr(): Expr
    {
        $expression = $this->parseAnd();

        while ($this->match(TokenType::OrOr)) {
            $operator = $this->previous();
            $expression = new BinaryExpr('||', $expression, $this->parseAnd(), $operator->line);
        }

        return $expression;
    }

    private function parseAnd(): Expr
    {
        $expression = $this->parseEquality();

        while ($this->match(TokenType::AndAnd)) {
            $operator = $this->previous();
            $expression = new BinaryExpr('&&', $expression, $this->parseEquality(), $operator->line);
        }

        return $expression;
    }

    private function parseEquality(): Expr
    {
        $expression = $this->parseComparison();

        while ($this->match(
            TokenType::Equal,
            TokenType::NotEqual,
            TokenType::Identical,
            TokenType::NotIdentical,
        )) {
            $operator = $this->previous();
            $expression = new BinaryExpr(
                $operator->lexeme,
                $expression,
                $this->parseComparison(),
                $operator->line,
            );
        }

        return $expression;
    }

    private function parseComparison(): Expr
    {
        $expression = $this->parseTerm();

        while ($this->match(
            TokenType::Less,
            TokenType::LessEqual,
            TokenType::Greater,
            TokenType::GreaterEqual,
        )) {
            $operator = $this->previous();
            $expression = new BinaryExpr(
                $operator->lexeme,
                $expression,
                $this->parseTerm(),
                $operator->line,
            );
        }

        return $expression;
    }

    private function parseTerm(): Expr
    {
        $expression = $this->parseFactor();

        while ($this->match(TokenType::Plus, TokenType::Minus, TokenType::Dot)) {
            $operator = $this->previous();
            $expression = new BinaryExpr(
                $operator->lexeme,
                $expression,
                $this->parseFactor(),
                $operator->line,
            );
        }

        return $expression;
    }

    private function parseFactor(): Expr
    {
        $expression = $this->parseUnary();

        while ($this->match(TokenType::Star, TokenType::Slash, TokenType::Percent)) {
            $operator = $this->previous();
            $expression = new BinaryExpr(
                $operator->lexeme,
                $expression,
                $this->parseUnary(),
                $operator->line,
            );
        }

        return $expression;
    }

    private function parseUnary(): Expr
    {
        if ($this->match(TokenType::Bang, TokenType::Minus, TokenType::Plus)) {
            $operator = $this->previous();

            return new UnaryExpr($operator->lexeme, $this->parseUnary(), $operator->line);
        }

        return $this->parsePostfix();
    }

    private function parsePostfix(): Expr
    {
        $expression = $this->parsePrimary();

        while ($this->match(TokenType::LeftBracket)) {
            $line = $this->previous()->line;

            if (!$expression instanceof VariableExpr) {
                throw new CompilerException(
                    "ligne {$line} : index imbriqué ou appel indexé non supporté",
                );
            }

            $index = null;

            if (!$this->check(TokenType::RightBracket)) {
                $index = $this->parseExpression();
            }

            $this->consume(TokenType::RightBracket, 'attendu : ] après l’index');
            $expression = new IndexExpr($expression, $index, $line);
        }

        return $expression;
    }

    private function parsePrimary(): Expr
    {
        if ($this->match(TokenType::Integer, TokenType::Float, TokenType::String)) {
            $token = $this->previous();
            $type = match ($token->type) {
                TokenType::Integer => 'int',
                TokenType::Float => 'float',
                default => 'string',
            };

            return new Literal($token->literal, $type, $token->line);
        }

        if ($this->match(TokenType::InterpolatedString)) {
            $token = $this->previous();
            $parts = $token->literal;

            if ($parts === []) {
                return new Literal('', 'string', $token->line);
            }

            if (count($parts) === 1 && $parts[0]['kind'] === 'literal') {
                return new Literal((string) $parts[0]['value'], 'string', $token->line);
            }

            $expression = new Literal('', 'string', $token->line);

            foreach ($parts as $part) {
                $partExpression = $part['kind'] === 'variable'
                    ? new VariableExpr((string) $part['value'], $token->line)
                    : new Literal((string) $part['value'], 'string', $token->line);
                $expression = new BinaryExpr('.', $expression, $partExpression, $token->line);
            }

            return $expression;
        }

        if ($this->match(TokenType::LeftBracket)) {
            $line = $this->previous()->line;

            return new ArrayExpr($this->parseArrayElements(TokenType::RightBracket), $line);
        }

        if ($this->match(TokenType::True, TokenType::False)) {
            $token = $this->previous();

            return new Literal($token->type === TokenType::True, 'bool', $token->line);
        }

        if ($this->match(TokenType::Null)) {
            return new Literal(null, 'null', $this->previous()->line);
        }

        if ($this->match(TokenType::Variable)) {
            $token = $this->previous();

            return new VariableExpr((string) $token->literal, $token->line);
        }

        if ($this->match(TokenType::Identifier)) {
            $token = $this->previous();
            $name = (string) $token->literal;

            if (strtolower($name) === 'array' && $this->check(TokenType::LeftParen)) {
                $this->advance();

                return new ArrayExpr(
                    $this->parseArrayElements(TokenType::RightParen),
                    $token->line,
                );
            }

            $this->consume(TokenType::LeftParen, 'attendu : ( après le nom de fonction');
            $arguments = [];

            if (!$this->check(TokenType::RightParen)) {
                do {
                    $arguments[] = $this->parseExpression();
                } while ($this->match(TokenType::Comma));
            }

            $this->consume(TokenType::RightParen, 'attendu : ) après les arguments');

            return new CallExpr($name, $arguments, $token->line);
        }

        if ($this->match(TokenType::LeftParen)) {
            $expression = $this->parseExpression();
            $this->consume(TokenType::RightParen, 'attendu : )');

            return $expression;
        }

        $token = $this->peek();
        throw new CompilerException(
            "ligne {$token->line} : expression attendue, trouvé {$token->description()}",
        );
    }

    /** @return list<Expr> */
    private function parseArrayElements(TokenType $closing): array
    {
        $elements = [];

        if (!$this->check($closing)) {
            do {
                $elements[] = $this->parseExpression();

                if ($this->check(TokenType::Arrow)) {
                    throw new CompilerException(
                        "ligne {$this->peek()->line} : tableaux associatifs non supportés (index entiers seulement)",
                    );
                }
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(
            $closing,
            'attendu : ' . ($closing === TokenType::RightBracket ? ']' : ')') . ' après le tableau',
        );

        return $elements;
    }

    private function parseType(): string
    {
        $token = $this->peek();
        $type = match ($token->type) {
            TokenType::IntType => 'int',
            TokenType::FloatType => 'float',
            TokenType::BoolType => 'bool',
            TokenType::StringType => 'string',
            TokenType::VoidType => 'void',
            default => null,
        };

        if ($type === null) {
            throw new CompilerException(
                "ligne {$token->line} : type attendu (int, float, bool, string, void), trouvé {$token->description()}",
            );
        }

        $this->advance();

        return $type;
    }

    private function match(TokenType ...$types): bool
    {
        foreach ($types as $type) {
            if ($this->check($type)) {
                $this->advance();
                return true;
            }
        }

        return false;
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function advance(): Token
    {
        if (!$this->check(TokenType::Eof)) {
            $this->current++;
        }

        return $this->previous();
    }

    private function peek(): Token
    {
        return $this->tokens[$this->current] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function previous(): Token
    {
        return $this->tokens[max(0, $this->current - 1)];
    }

    private function consume(TokenType $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        $token = $this->peek();
        throw new CompilerException("ligne {$token->line} : {$message}, trouvé {$token->description()}");
    }
}
