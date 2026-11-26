<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Parser;

use Cyonima\Compiler\Ast\AppendExpr;
use Cyonima\Compiler\Ast\ArrayExpr;
use Cyonima\Compiler\Ast\ArrayItem;
use Cyonima\Compiler\Ast\AssignExpr;
use Cyonima\Compiler\Ast\BinaryExpr;
use Cyonima\Compiler\Ast\Block;
use Cyonima\Compiler\Ast\BreakStmt;
use Cyonima\Compiler\Ast\CallableCallExpr;
use Cyonima\Compiler\Ast\CallExpr;
use Cyonima\Compiler\Ast\CatchClause;
use Cyonima\Compiler\Ast\ClassDecl;
use Cyonima\Compiler\Ast\ClosureExpr;
use Cyonima\Compiler\Ast\EchoStmt;
use Cyonima\Compiler\Ast\Expr;
use Cyonima\Compiler\Ast\ExpressionStmt;
use Cyonima\Compiler\Ast\ForStmt;
use Cyonima\Compiler\Ast\ForeachStmt;
use Cyonima\Compiler\Ast\FunctionDecl;
use Cyonima\Compiler\Ast\IfStmt;
use Cyonima\Compiler\Ast\IndexAssignExpr;
use Cyonima\Compiler\Ast\IndexExpr;
use Cyonima\Compiler\Ast\InterfaceDecl;
use Cyonima\Compiler\Ast\Literal;
use Cyonima\Compiler\Ast\MatchArm;
use Cyonima\Compiler\Ast\MatchExpr;
use Cyonima\Compiler\Ast\MethodCallExpr;
use Cyonima\Compiler\Ast\MethodDecl;
use Cyonima\Compiler\Ast\NewExpr;
use Cyonima\Compiler\Ast\Param;
use Cyonima\Compiler\Ast\ParentCallExpr;
use Cyonima\Compiler\Ast\Program;
use Cyonima\Compiler\Ast\PropertyAccessExpr;
use Cyonima\Compiler\Ast\PropertyAssignExpr;
use Cyonima\Compiler\Ast\PropertyDecl;
use Cyonima\Compiler\Ast\ReturnStmt;
use Cyonima\Compiler\Ast\StaticCallExpr;
use Cyonima\Compiler\Ast\StaticPropertyAssignExpr;
use Cyonima\Compiler\Ast\StaticPropertyExpr;
use Cyonima\Compiler\Ast\Stmt;
use Cyonima\Compiler\Ast\SwitchCase;
use Cyonima\Compiler\Ast\SwitchStmt;
use Cyonima\Compiler\Ast\ThrowStmt;
use Cyonima\Compiler\Ast\TryStmt;
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
        $classes = [];
        $interfaces = [];
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

            if ($this->match(TokenType::AbstractKeyword)) {
                $abstract = $this->previous();
                $this->consume(TokenType::ClassKeyword, 'attendu : class après abstract');
                $classes[] = $this->parseClassAfterKeyword($abstract, true);
                continue;
            }

            if ($this->match(TokenType::ClassKeyword)) {
                $classes[] = $this->parseClassAfterKeyword($this->previous(), false);
                continue;
            }

            if ($this->match(TokenType::InterfaceKeyword)) {
                $interfaces[] = $this->parseInterfaceAfterKeyword($this->previous());
                continue;
            }

            $statements[] = $this->parseStatement();
        }

        return new Program($functions, $classes, $interfaces, $statements);
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

        $doc = $keyword->docComment;
        $params = $this->applyParamDocblock($params, $doc);
        $returnType = $this->applyReturnDocblock($returnType, $doc);

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

    private function parseClassAfterKeyword(Token $keyword, bool $isAbstract): ClassDecl
    {
        $name = $this->consume(TokenType::Identifier, 'attendu : nom de classe après class');
        $parentName = null;

        if ($this->match(TokenType::ExtendsKeyword)) {
            $parent = $this->consume(
                TokenType::Identifier,
                'attendu : nom de classe parente après extends',
            );
            $parentName = (string) $parent->literal;
        }

        $interfaces = [];

        if ($this->match(TokenType::ImplementsKeyword)) {
            do {
                $interface = $this->consume(
                    TokenType::Identifier,
                    'attendu : nom d’interface après implements',
                );
                $interfaces[] = (string) $interface->literal;
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(TokenType::LeftBrace, 'attendu : { après le nom de classe');
        $properties = [];
        $methods = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::Eof)) {
            $line = $this->peek()->line;
            $doc = $this->peek()->docComment;
            $memberAbstract = false;
            $memberStatic = false;

            while (true) {
                if ($this->match(TokenType::AbstractKeyword)) {
                    $memberAbstract = true;
                    continue;
                }

                if ($this->match(TokenType::StaticKeyword)) {
                    $memberStatic = true;
                    continue;
                }

                if ($this->match(
                    TokenType::PublicKeyword,
                    TokenType::PrivateKeyword,
                    TokenType::ProtectedKeyword,
                )) {
                    continue;
                }

                break;
            }

            if ($this->match(TokenType::Function)) {
                if ($memberStatic && $memberAbstract) {
                    throw new CompilerException(
                        "ligne {$line} : méthode static abstraite non supportée",
                    );
                }

                $methods[] = $this->parseMethodAfterFunctionKeyword(
                    $this->previous(),
                    $memberAbstract,
                    $memberStatic,
                    $doc,
                );
                continue;
            }

            if ($memberAbstract) {
                throw new CompilerException(
                    "ligne {$line} : abstract doit précéder une méthode",
                );
            }

            $type = $this->applyPropertyDocblock($this->parseType(), $doc);

            if ($type === 'void') {
                throw new CompilerException(
                    "ligne {$line} : une propriété ne peut pas être de type void",
                );
            }

            $variable = $this->consume(TokenType::Variable, 'attendu : nom de propriété $nom');
            $this->match(TokenType::Assign);

            if ($this->previous()->type === TokenType::Assign) {
                throw new CompilerException(
                    "ligne {$line} : valeur par défaut de propriété non supportée",
                );
            }

            $this->consume(TokenType::Semicolon, 'attendu : ; après la propriété');
            $properties[] = new PropertyDecl(
                $type,
                (string) $variable->literal,
                $memberStatic,
                $line,
            );
        }

        $this->consume(TokenType::RightBrace, 'attendu : } après la classe');

        return new ClassDecl(
            (string) $name->literal,
            $parentName,
            $interfaces,
            $isAbstract,
            $properties,
            $methods,
            $keyword->line,
        );
    }

    private function parseInterfaceAfterKeyword(Token $keyword): InterfaceDecl
    {
        $name = $this->consume(TokenType::Identifier, 'attendu : nom d’interface');
        $parents = [];

        if ($this->match(TokenType::ExtendsKeyword)) {
            do {
                $parent = $this->consume(
                    TokenType::Identifier,
                    'attendu : nom d’interface parente après extends',
                );
                $parents[] = (string) $parent->literal;
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(TokenType::LeftBrace, 'attendu : { après le nom d’interface');
        $methods = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::Eof)) {
            $line = $this->peek()->line;
            $doc = $this->peek()->docComment;
            $this->match(TokenType::AbstractKeyword);
            $this->match(TokenType::PublicKeyword, TokenType::PrivateKeyword, TokenType::ProtectedKeyword);
            $this->match(TokenType::AbstractKeyword);

            if ($this->match(TokenType::StaticKeyword)) {
                throw new CompilerException(
                    "ligne {$line} : membre static interdit dans une interface",
                );
            }

            if (!$this->match(TokenType::Function)) {
                throw new CompilerException(
                    "ligne {$line} : une interface ne peut contenir que des méthodes",
                );
            }

            $methods[] = $this->parseMethodAfterFunctionKeyword(
                $this->previous(),
                true,
                false,
                $doc,
            );
        }

        $this->consume(TokenType::RightBrace, 'attendu : } après l’interface');

        return new InterfaceDecl(
            (string) $name->literal,
            $parents,
            $methods,
            $keyword->line,
        );
    }

    private function parseMethodAfterFunctionKeyword(
        Token $keyword,
        bool $isAbstract,
        bool $isStatic = false,
        ?string $doc = null,
    ): MethodDecl {
        $name = $this->consume(TokenType::Identifier, 'attendu : nom de méthode après function');

        if ($isStatic && $name->literal === '__construct') {
            throw new CompilerException(
                "ligne {$name->line} : __construct ne peut pas être static",
            );
        }

        $this->consume(TokenType::LeftParen, 'attendu : ( après le nom de méthode');
        $params = $this->parseParameters();
        $this->consume(TokenType::RightParen, 'attendu : ) après les paramètres');
        $returnType = 'void';

        if ($this->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $doc = $keyword->docComment ?? $doc;
        $params = $this->applyParamDocblock($params, $doc);
        $returnType = $this->applyReturnDocblock($returnType, $doc);

        $body = null;

        if ($this->match(TokenType::Semicolon)) {
            if (!$isAbstract) {
                throw new CompilerException(
                    "ligne {$keyword->line} : méthode sans corps : ajoutez abstract",
                );
            }
        } else {
            if ($isAbstract) {
                throw new CompilerException(
                    "ligne {$keyword->line} : une méthode abstraite ne peut pas avoir de corps",
                );
            }

            $this->consume(TokenType::LeftBrace, 'attendu : { pour le corps de la méthode');
            $body = $this->parseBlockAfterBrace($keyword->line);
        }

        return new MethodDecl(
            (string) $name->literal,
            $params,
            $returnType,
            $body,
            $isAbstract,
            $isStatic,
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

        if ($this->match(TokenType::Switch)) {
            return $this->parseSwitchAfterKeyword($token);
        }

        if ($this->match(TokenType::Break)) {
            $this->consume(TokenType::Semicolon, 'attendu : ; après break');

            return new BreakStmt($token->line);
        }

        if ($this->match(TokenType::Try)) {
            return $this->parseTryAfterKeyword($token);
        }

        if ($this->match(TokenType::Throw)) {
            $value = $this->parseExpression();
            $this->consume(TokenType::Semicolon, 'attendu : ; après throw');

            return new ThrowStmt($value, $token->line);
        }

        if ($this->match(TokenType::Finally)) {
            throw new CompilerException(
                "ligne {$token->line} : finally non supporté",
            );
        }

        if ($this->match(TokenType::Case, TokenType::Default)) {
            throw new CompilerException(
                "ligne {$token->line} : {$this->previous()->lexeme} hors switch",
            );
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

    private function parseSwitchAfterKeyword(Token $keyword): SwitchStmt
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après switch');
        $subject = $this->parseExpression();
        $this->consume(TokenType::RightParen, 'attendu : ) après switch');
        $this->consume(TokenType::LeftBrace, 'attendu : { après switch');
        $cases = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::Eof)) {
            $line = $this->peek()->line;

            if ($this->match(TokenType::Case)) {
                $condition = $this->parseExpression();
                $this->consume(TokenType::Colon, 'attendu : : après case');
                $cases[] = new SwitchCase(
                    $condition,
                    $this->parseCaseStatements(),
                    $line,
                );
                continue;
            }

            if ($this->match(TokenType::Default)) {
                $this->consume(TokenType::Colon, 'attendu : : après default');
                $cases[] = new SwitchCase(
                    null,
                    $this->parseCaseStatements(),
                    $line,
                );
                continue;
            }

            throw new CompilerException(
                "ligne {$line} : case ou default attendu dans switch",
            );
        }

        $this->consume(TokenType::RightBrace, 'attendu : } après switch');

        return new SwitchStmt($subject, $cases, $keyword->line);
    }

    /** @return list<Stmt> */
    private function parseCaseStatements(): array
    {
        $statements = [];

        while (!$this->check(TokenType::Case)
            && !$this->check(TokenType::Default)
            && !$this->check(TokenType::RightBrace)
            && !$this->check(TokenType::Eof)) {
            $statements[] = $this->parseStatement();
        }

        return $statements;
    }

    private function parseTryAfterKeyword(Token $keyword): TryStmt
    {
        $this->consume(TokenType::LeftBrace, 'attendu : { après try');
        $body = $this->parseBlockAfterBrace($keyword->line);
        $this->consume(TokenType::Catch, 'attendu : catch après try');
        $this->consume(TokenType::LeftParen, 'attendu : ( après catch');
        $type = $this->parseType();
        $variable = $this->consume(TokenType::Variable, 'attendu : variable après le type de catch');
        $this->consume(TokenType::RightParen, 'attendu : ) après catch');
        $this->consume(TokenType::LeftBrace, 'attendu : { après catch');
        $catchBody = $this->parseBlockAfterBrace($keyword->line);

        if ($this->check(TokenType::Catch)) {
            throw new CompilerException(
                "ligne {$this->peek()->line} : un seul catch est supporté",
            );
        }

        if ($this->check(TokenType::Finally)) {
            throw new CompilerException(
                "ligne {$this->peek()->line} : finally non supporté",
            );
        }

        return new TryStmt(
            $body,
            new CatchClause(
                $type,
                (string) $variable->literal,
                $catchBody,
                $keyword->line,
            ),
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

            if ($expression instanceof PropertyAccessExpr) {
                return new PropertyAssignExpr(
                    $expression->object,
                    $expression->property,
                    $value,
                    $operator->line,
                );
            }

            if ($expression instanceof StaticPropertyExpr) {
                return new StaticPropertyAssignExpr(
                    $expression->className,
                    $expression->property,
                    $value,
                    $operator->line,
                );
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

        while (true) {
            if ($this->match(TokenType::LeftBracket)) {
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
                continue;
            }

            if ($this->match(TokenType::ObjectArrow)) {
                $line = $this->previous()->line;
                $name = $this->consume(
                    TokenType::Identifier,
                    'attendu : nom de propriété ou méthode après ->',
                );

                if ($this->match(TokenType::LeftParen)) {
                    $arguments = [];

                    if (!$this->check(TokenType::RightParen)) {
                        do {
                            $arguments[] = $this->parseExpression();
                        } while ($this->match(TokenType::Comma));
                    }

                    $this->consume(TokenType::RightParen, 'attendu : ) après les arguments');
                    $expression = new MethodCallExpr(
                        $expression,
                        (string) $name->literal,
                        $arguments,
                        $line,
                    );
                    continue;
                }

                $expression = new PropertyAccessExpr(
                    $expression,
                    (string) $name->literal,
                    $line,
                );
                continue;
            }

            if ($this->match(TokenType::LeftParen)) {
                $line = $this->previous()->line;

                if (!$expression instanceof VariableExpr) {
                    throw new CompilerException(
                        "ligne {$line} : seul un appel de closure via variable est supporté",
                    );
                }

                $arguments = [];

                if (!$this->check(TokenType::RightParen)) {
                    do {
                        $arguments[] = $this->parseExpression();
                    } while ($this->match(TokenType::Comma));
                }

                $this->consume(TokenType::RightParen, 'attendu : ) après les arguments');
                $expression = new CallableCallExpr(
                    $expression,
                    $arguments,
                    $line,
                );
                continue;
            }

            break;
        }

        return $expression;
    }

    private function parsePrimary(): Expr
    {
        if ($this->match(TokenType::Function)) {
            return $this->parseAnonymousFunctionAfterKeyword($this->previous());
        }

        if ($this->match(TokenType::FnKeyword)) {
            return $this->parseArrowFunctionAfterKeyword($this->previous());
        }

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

        if ($this->match(TokenType::Match)) {
            return $this->parseMatchAfterKeyword($this->previous());
        }

        if ($this->match(TokenType::NewKeyword)) {
            $token = $this->previous();
            $className = $this->consume(TokenType::Identifier, 'attendu : nom de classe après new');
            $this->consume(TokenType::LeftParen, 'attendu : ( après le nom de classe');
            $arguments = [];

            if (!$this->check(TokenType::RightParen)) {
                do {
                    $arguments[] = $this->parseExpression();
                } while ($this->match(TokenType::Comma));
            }

            $this->consume(TokenType::RightParen, 'attendu : ) après les arguments de new');

            return new NewExpr((string) $className->literal, $arguments, $token->line);
        }

        if ($this->match(TokenType::ParentKeyword)) {
            $token = $this->previous();
            $this->consume(TokenType::DoubleColon, 'attendu : :: après parent');
            $method = $this->consume(TokenType::Identifier, 'attendu : nom de méthode après parent::');
            $this->consume(TokenType::LeftParen, 'attendu : ( après parent::');
            $arguments = [];

            if (!$this->check(TokenType::RightParen)) {
                do {
                    $arguments[] = $this->parseExpression();
                } while ($this->match(TokenType::Comma));
            }

            $this->consume(TokenType::RightParen, 'attendu : ) après parent::');

            return new ParentCallExpr((string) $method->literal, $arguments, $token->line);
        }

        if ($this->match(TokenType::StaticKeyword)) {
            $token = $this->previous();

            if ($this->check(TokenType::DoubleColon)) {
                throw new CompilerException(
                    "ligne {$token->line} : static:: non supporté, utilisez self::",
                );
            }

            throw new CompilerException(
                "ligne {$token->line} : static utilisé hors d’une déclaration de membre",
            );
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

            if ($this->check(TokenType::DoubleColon)) {
                return $this->parseStaticAccess($token, $name);
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

    private function parseStaticAccess(Token $classToken, string $className): Expr
    {
        $this->consume(TokenType::DoubleColon, 'attendu : ::');

        if ($this->match(TokenType::Variable)) {
            $property = $this->previous();

            return new StaticPropertyExpr(
                $className,
                (string) $property->literal,
                $classToken->line,
            );
        }

        $member = $this->consume(
            TokenType::Identifier,
            'attendu : nom de méthode, $propriété ou constante après ::',
        );

        if (!$this->check(TokenType::LeftParen)) {
            throw new CompilerException(
                "ligne {$classToken->line} : constante de classe non supportée : "
                . $className . '::' . $member->literal,
            );
        }

        $this->consume(TokenType::LeftParen, 'attendu : ( après ::');
        $arguments = [];

        if (!$this->check(TokenType::RightParen)) {
            do {
                $arguments[] = $this->parseExpression();
            } while ($this->match(TokenType::Comma));
        }

        $this->consume(TokenType::RightParen, 'attendu : ) après les arguments');

        return new StaticCallExpr(
            $className,
            (string) $member->literal,
            $arguments,
            $classToken->line,
        );
    }

    /** @return list<Expr> */
    private function parseArrayElements(TokenType $closing): array
    {
        $elements = [];

        while (!$this->check($closing)) {
            $line = $this->peek()->line;
            $first = $this->parseExpression();

            if ($this->match(TokenType::Arrow)) {
                $value = $this->parseExpression();
                $elements[] = new ArrayItem($first, $value, $line);
            } else {
                $elements[] = new ArrayItem(null, $first, $line);
            }

            if (!$this->match(TokenType::Comma)) {
                break;
            }
        }

        $this->consume(
            $closing,
            'attendu : ' . ($closing === TokenType::RightBracket ? ']' : ')') . ' après le tableau',
        );

        return $elements;
    }

    /** @return list<Param> */
    private function parseParameters(): array
    {
        $params = [];

        if (!$this->check(TokenType::RightParen)) {
            do {
                $type = $this->parseType();
                $variable = $this->consume(
                    TokenType::Variable,
                    'attendu : paramètre $nom après son type',
                );
                $params[] = new Param((string) $variable->literal, $type, $variable->line);
            } while ($this->match(TokenType::Comma));
        }

        return $params;
    }

    private function parseAnonymousFunctionAfterKeyword(Token $keyword): ClosureExpr
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après function anonyme');
        $params = $this->parseParameters();
        $this->consume(TokenType::RightParen, 'attendu : ) après les paramètres');
        $use = [];
        $useByRef = [];

        if ($this->match(TokenType::UseKeyword)) {
            $this->consume(TokenType::LeftParen, 'attendu : ( après use');

            if (!$this->check(TokenType::RightParen)) {
                do {
                    $byRef = $this->match(TokenType::Ampersand);
                    $variable = $this->consume(
                        TokenType::Variable,
                        'attendu : variable capturée par use',
                    );

                    if ($byRef) {
                        $useByRef[] = (string) $variable->literal;
                    } else {
                        $use[] = (string) $variable->literal;
                    }
                } while ($this->match(TokenType::Comma));
            }

            $this->consume(TokenType::RightParen, 'attendu : ) après use');
        }

        $returnType = null;

        if ($this->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::LeftBrace, 'attendu : { pour la closure');
        $body = $this->parseBlockAfterBrace($keyword->line);

        return new ClosureExpr(
            false,
            $params,
            $returnType,
            $body,
            null,
            $use,
            $useByRef,
            $keyword->line,
        );
    }

    private function parseArrowFunctionAfterKeyword(Token $keyword): ClosureExpr
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après fn');
        $params = $this->parseParameters();
        $this->consume(TokenType::RightParen, 'attendu : ) après les paramètres');
        $returnType = null;

        if ($this->match(TokenType::Colon)) {
            $returnType = $this->parseType();
        }

        $this->consume(TokenType::Arrow, 'attendu : => après fn');
        $expression = $this->parseExpression();

        return new ClosureExpr(
            true,
            $params,
            $returnType,
            null,
            $expression,
            [],
            [],
            $keyword->line,
        );
    }

    private function parseMatchAfterKeyword(Token $keyword): MatchExpr
    {
        $this->consume(TokenType::LeftParen, 'attendu : ( après match');
        $subject = $this->parseExpression();
        $this->consume(TokenType::RightParen, 'attendu : ) après match');
        $this->consume(TokenType::LeftBrace, 'attendu : { après match');
        $arms = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::Eof)) {
            $line = $this->peek()->line;
            $conditions = [];

            if ($this->match(TokenType::Default)) {
                // Arm par défaut.
            } else {
                $conditions[] = $this->parseExpression();

                while ($this->match(TokenType::Comma)) {
                    if ($this->check(TokenType::Arrow)) {
                        break;
                    }

                    $conditions[] = $this->parseExpression();
                }
            }

            $this->consume(TokenType::Arrow, 'attendu : => dans match');
            $body = $this->parseExpression();
            $arms[] = new MatchArm($conditions, $body, $line);

            if (!$this->match(TokenType::Comma)) {
                break;
            }
        }

        $this->consume(TokenType::RightBrace, 'attendu : } après match');

        return new MatchExpr($subject, $arms, $keyword->line);
    }

    private function parseType(): string
    {
        $token = $this->peek();

        if ($this->match(TokenType::Question)) {
            $question = $this->previous();
            $inner = $this->parseType();

            if ($inner === 'void' || str_starts_with($inner, '?')) {
                throw new CompilerException(
                    "ligne {$question->line} : type nullable invalide : ?{$inner}",
                );
            }

            return '?' . $inner;
        }

        if ($token->type === TokenType::Identifier
            && strtolower((string) $token->literal) === 'callable') {
            $this->advance();

            if ($this->match(TokenType::LeftParen)) {
                $params = [];

                if (!$this->check(TokenType::RightParen)) {
                    do {
                        $param = $this->parseType();

                        if (!in_array($param, ['int', 'float', 'bool', 'string'], true)) {
                            throw new CompilerException(
                                "ligne {$token->line} : paramètre callable non scalaire : {$param}",
                            );
                        }

                        $params[] = $param;
                    } while ($this->match(TokenType::Comma));
                }

                $this->consume(TokenType::RightParen, 'attendu : ) dans la signature callable');
                $this->consume(TokenType::Colon, 'attendu : : dans la signature callable');
                $return = $this->parseType();

                if (!in_array($return, ['int', 'float', 'bool', 'string', 'void'], true)) {
                    throw new CompilerException(
                        "ligne {$token->line} : retour callable non scalaire : {$return}",
                    );
                }

                return 'callable(' . implode(',', $params) . '):' . $return;
            }

            return 'callable';
        }

        if ($token->type === TokenType::Identifier) {
            $this->advance();
            $name = (string) $token->literal;

            if (strtolower($name) === 'array' && $this->check(TokenType::Less)) {
                return $this->parseArrayType($token);
            }

            return $name;
        }

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

    private function parseArrayType(Token $token): string
    {
        $this->consume(TokenType::Less, 'attendu : < après array');

        if ($this->match(TokenType::StringType)) {
            if ($this->match(TokenType::Comma)) {
                $value = $this->parseArrayElementType($token);
                $this->consume(
                    TokenType::Greater,
                    'attendu : > après le type de tableau associatif',
                );

                return 'array<string,' . $value . '>';
            }

            $this->consume(TokenType::Greater, 'attendu : > après le type de tableau');

            return 'array<string>';
        }

        $element = $this->parseArrayElementType($token);
        $this->consume(TokenType::Greater, 'attendu : > après le type de tableau');

        return 'array<' . $element . '>';
    }

    private function parseArrayElementType(Token $token): string
    {
        $element = match ($this->peek()->type) {
            TokenType::IntType => 'int',
            TokenType::FloatType => 'float',
            TokenType::BoolType => 'bool',
            TokenType::StringType => 'string',
            default => null,
        };

        if ($element === null) {
            throw new CompilerException(
                "ligne {$token->line} : élément de tableau scalaire attendu (int, float, bool, string)",
            );
        }

        $this->advance();

        return $element;
    }

    /**
     * @param list<Param> $params
     * @return list<Param>
     */
    private function applyParamDocblock(array $params, ?string $doc): array
    {
        $annotations = $this->parseDocblock($doc);

        if ($annotations['params'] === []) {
            return $params;
        }

        $result = [];

        foreach ($params as $param) {
            $type = $param->type;

            if ($type === 'array' && isset($annotations['params'][$param->name])) {
                $type = $annotations['params'][$param->name];
            }

            $result[] = $type === $param->type
                ? $param
                : new Param($param->name, $type, $param->line);
        }

        return $result;
    }

    private function applyReturnDocblock(string $returnType, ?string $doc): string
    {
        if ($returnType !== 'array') {
            return $returnType;
        }

        $annotations = $this->parseDocblock($doc);

        return $annotations['return'] ?? $returnType;
    }

    private function applyPropertyDocblock(string $type, ?string $doc): string
    {
        if ($type !== 'array') {
            return $type;
        }

        $annotations = $this->parseDocblock($doc);

        return $annotations['var'] ?? $type;
    }

    /**
     * @return array{params: array<string, string>, return: ?string, var: ?string}
     */
    private function parseDocblock(?string $doc): array
    {
        $annotations = ['params' => [], 'return' => null, 'var' => null];

        if ($doc === null) {
            return $annotations;
        }

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = ltrim(trim($line), "*/ \t");

            if ($line === '') {
                continue;
            }

            if (preg_match(
                '/^@param\s+(.+?)\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
                $line,
                $matches,
            ) === 1) {
                $type = $this->docblockArrayType($matches[1]);

                if ($type !== null) {
                    $annotations['params'][$matches[2]] = $type;
                }

                continue;
            }

            if (preg_match(
                '/^@return\s+(array<[^>]+>|[^\s]+)/',
                $line,
                $matches,
            ) === 1) {
                $type = $this->docblockArrayType($matches[1]);

                if ($type !== null) {
                    $annotations['return'] = $type;
                }

                continue;
            }

            if (preg_match(
                '/^@var\s+(array<[^>]+>|[^\s]+)/',
                $line,
                $matches,
            ) === 1) {
                $type = $this->docblockArrayType($matches[1]);

                if ($type !== null) {
                    $annotations['var'] = $type;
                }
            }
        }

        return $annotations;
    }

    private function docblockArrayType(string $type): ?string
    {
        $type = str_replace([' ', "\t"], '', $type);

        if (preg_match('/^array<([^,<>]+)>$/', $type) === 1
            || preg_match('/^array<string,([^,<>]+)>$/', $type) === 1) {
            return $type;
        }

        return null;
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
