<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Lexer;

use Cyonima\Compiler\CompilerException;

/**
 * Analyseur lexical du sous-ensemble PHP compilé.
 *
 * Le lexer ne reconnaît volontairement qu’une grammaire stricte. Toute
 * construction hors périmètre lève une CompilerException avec sa ligne.
 */
final class Lexer
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $length;
    private int $position = 0;
    private int $line = 1;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /** @return list<Token> */
    public function tokenize(): array
    {
        $this->skipBomAndWhitespace();
        $this->consumeOpenTag();

        while (!$this->atEnd()) {
            $this->scanToken();
        }

        $this->tokens[] = new Token(TokenType::Eof, '', null, $this->line);

        return $this->tokens;
    }

    private function skipBomAndWhitespace(): void
    {
        if (str_starts_with($this->source, "\xEF\xBB\xBF")) {
            $this->position = 3;
        }

        while (!$this->atEnd() && ctype_space($this->source[$this->position])) {
            $this->advance();
        }
    }

    private function consumeOpenTag(): void
    {
        if (substr($this->source, $this->position, 5) === '<?php') {
            $this->position += 5;
            return;
        }

        if (substr($this->source, $this->position, 2) === '<?') {
            $this->position += 2;
            return;
        }

        throw new CompilerException('ligne 1 : balise <?php manquante');
    }

    private function scanToken(): void
    {
        $char = $this->peek();

        if (ctype_space($char)) {
            $this->advance();
            return;
        }

        if ($char === '/' && $this->peek(1) === '/') {
            $this->skipLineComment();
            return;
        }

        if ($char === '#') {
            $this->skipLineComment();
            return;
        }

        if ($char === '/' && $this->peek(1) === '*') {
            $this->skipBlockComment();
            return;
        }

        if ($char === '?' && $this->peek(1) === '>') {
            $this->position = $this->length;
            return;
        }

        if ($char === '$') {
            $this->scanVariable();
            return;
        }

        if (ctype_alpha($char) || $char === '_') {
            $this->scanIdentifier();
            return;
        }

        if (ctype_digit($char)) {
            $this->scanNumber();
            return;
        }

        if ($char === '"' || $char === "'") {
            $this->scanString($char);
            return;
        }

        $this->scanOperator($char);
    }

    private function skipLineComment(): void
    {
        while (!$this->atEnd() && $this->peek() !== "\n") {
            $this->advance();
        }
    }

    private function skipBlockComment(): void
    {
        $start = $this->line;
        $this->advance();
        $this->advance();

        while (!$this->atEnd()) {
            if ($this->peek() === '*' && $this->peek(1) === '/') {
                $this->advance();
                $this->advance();
                return;
            }
            $this->advance();
        }

        throw new CompilerException("ligne {$start} : commentaire /* non terminé");
    }

    private function scanVariable(): void
    {
        $start = $this->position;
        $line = $this->line;
        $this->advance();

        if (!$this->atEnd() && (ctype_alpha($this->peek()) || $this->peek() === '_')) {
            while (!$this->atEnd() && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
                $this->advance();
            }
        }

        $lexeme = substr($this->source, $start, $this->position - $start);
        $name = substr($lexeme, 1);

        if ($name === '') {
            throw new CompilerException("ligne {$line} : nom de variable attendu après $");
        }

        $this->tokens[] = new Token(TokenType::Variable, $lexeme, $name, $line);
    }

    private function scanIdentifier(): void
    {
        $start = $this->position;
        $line = $this->line;

        while (!$this->atEnd() && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $this->advance();
        }

        $lexeme = substr($this->source, $start, $this->position - $start);
        $type = match (strtolower($lexeme)) {
            'function' => TokenType::Function,
            'return' => TokenType::Return,
            'echo' => TokenType::Echo,
            'if' => TokenType::If,
            'elseif' => TokenType::ElseIf,
            'else' => TokenType::Else,
            'while' => TokenType::While,
            'for' => TokenType::For,
            'foreach' => TokenType::ForEach,
            'as' => TokenType::As,
            'declare' => TokenType::Declare,
            'true' => TokenType::True,
            'false' => TokenType::False,
            'null' => TokenType::Null,
            'int' => TokenType::IntType,
            'float' => TokenType::FloatType,
            'bool' => TokenType::BoolType,
            'string' => TokenType::StringType,
            'void' => TokenType::VoidType,
            default => TokenType::Identifier,
        };

        $this->tokens[] = new Token($type, $lexeme, $lexeme, $line);
    }

    private function scanNumber(): void
    {
        $start = $this->position;
        $line = $this->line;
        $isFloat = false;

        while (!$this->atEnd() && ctype_digit($this->peek())) {
            $this->advance();
        }

        if (!$this->atEnd() && $this->peek() === '.' && ctype_digit($this->peek(1))) {
            $isFloat = true;
            $this->advance();
            while (!$this->atEnd() && ctype_digit($this->peek())) {
                $this->advance();
            }
        }

        if (!$this->atEnd() && ($this->peek() === 'e' || $this->peek() === 'E')) {
            $next = $this->peek(1);
            $afterSign = $this->peek(2);

            if (ctype_digit($next) || (($next === '+' || $next === '-') && ctype_digit($afterSign))) {
                $isFloat = true;
                $this->advance();
                if ($this->peek() === '+' || $this->peek() === '-') {
                    $this->advance();
                }
                while (!$this->atEnd() && ctype_digit($this->peek())) {
                    $this->advance();
                }
            }
        }

        $lexeme = substr($this->source, $start, $this->position - $start);
        $literal = $isFloat ? (float) $lexeme : (int) $lexeme;
        $this->tokens[] = new Token(
            $isFloat ? TokenType::Float : TokenType::Integer,
            $lexeme,
            $literal,
            $line,
        );
    }

    private function scanString(string $quote): void
    {
        $line = $this->line;
        $this->advance();
        $value = '';
        $parts = [];

        $flush = function () use (&$value, &$parts): void {
            if ($value !== '') {
                $parts[] = ['kind' => 'literal', 'value' => $value];
                $value = '';
            }
        };

        while (!$this->atEnd()) {
            $char = $this->advance();

            if ($char === $quote) {
                if ($quote === "'") {
                    $this->tokens[] = new Token(TokenType::String, $value, $value, $line);
                    return;
                }

                $flush();
                $this->tokens[] = new Token(TokenType::InterpolatedString, '"', $parts, $line);
                return;
            }

            if ($char === '\\') {
                if ($this->atEnd()) {
                    break;
                }

                $escaped = $this->advance();

                if ($quote === "'") {
                    $value .= match ($escaped) {
                        '\\' => '\\',
                        "'" => "'",
                        default => '\\' . $escaped,
                    };
                    continue;
                }

                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    '$' => '$',
                    '0' => "\0",
                    default => '\\' . $escaped,
                };
                continue;
            }

            if ($quote === '"' && $char === '{' && $this->peek() === '$') {
                $flush();
                $this->advance();
                $name = $this->scanInterpolationName();

                if ($name === '') {
                    throw new CompilerException(
                        "ligne {$this->line} : variable attendue dans l’interpolation {\\$...}",
                    );
                }

                if ($this->peek() !== '}') {
                    throw new CompilerException(
                        "ligne {$this->line} : interpolation complexe non supportée ; utilisez la concaténation avec .",
                    );
                }

                $this->advance();
                $parts[] = ['kind' => 'variable', 'value' => $name];
                continue;
            }

            if ($quote === '"' && $char === '$') {
                $name = $this->scanInterpolationName();

                if ($name === '') {
                    if ($this->peek() === '{') {
                        throw new CompilerException(
                            "ligne {$this->line} : interpolation \${...} non supportée",
                        );
                    }

                    $value .= '$';
                    continue;
                }

                if ($this->peek() === '[') {
                    throw new CompilerException(
                        "ligne {$this->line} : interpolation d’index non supportée ; utilisez la concaténation avec .",
                    );
                }

                if ($this->peek() === '-' && $this->peek(1) === '>') {
                    throw new CompilerException(
                        "ligne {$this->line} : interpolation de propriété non supportée",
                    );
                }

                $flush();
                $parts[] = ['kind' => 'variable', 'value' => $name];
                continue;
            }

            $value .= $char;
        }

        throw new CompilerException("ligne {$line} : chaîne non terminée");
    }

    private function scanInterpolationName(): string
    {
        if ($this->atEnd() || (!ctype_alpha($this->peek()) && $this->peek() !== '_')) {
            return '';
        }

        $start = $this->position;

        while (!$this->atEnd() && (ctype_alnum($this->peek()) || $this->peek() === '_')) {
            $this->advance();
        }

        return substr($this->source, $start, $this->position - $start);
    }

    private function scanOperator(string $char): void
    {
        $line = $this->line;
        $three = substr($this->source, $this->position, 3);
        $two = substr($this->source, $this->position, 2);

        $threeChar = match ($three) {
            '===' => TokenType::Identical,
            '!==' => TokenType::NotIdentical,
            default => null,
        };

        if ($threeChar !== null) {
            $this->position += 3;
            $this->tokens[] = new Token($threeChar, $three, null, $line);
            return;
        }

        $twoChar = match ($two) {
            '==' => TokenType::Equal,
            '!=' => TokenType::NotEqual,
            '<=' => TokenType::LessEqual,
            '>=' => TokenType::GreaterEqual,
            '&&' => TokenType::AndAnd,
            '||' => TokenType::OrOr,
            '=>' => TokenType::Arrow,
            default => null,
        };

        if ($twoChar !== null) {
            $this->position += 2;
            $this->tokens[] = new Token($twoChar, $two, null, $line);
            return;
        }

        $single = match ($char) {
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '%' => TokenType::Percent,
            '.' => TokenType::Dot,
            '=' => TokenType::Assign,
            '<' => TokenType::Less,
            '>' => TokenType::Greater,
            '!' => TokenType::Bang,
            '(' => TokenType::LeftParen,
            ')' => TokenType::RightParen,
            '{' => TokenType::LeftBrace,
            '}' => TokenType::RightBrace,
            '[' => TokenType::LeftBracket,
            ']' => TokenType::RightBracket,
            ',' => TokenType::Comma,
            ';' => TokenType::Semicolon,
            ':' => TokenType::Colon,
            default => null,
        };

        if ($single === null) {
            throw new CompilerException("ligne {$line} : caractère inattendu « {$char} »");
        }

        $this->advance();
        $this->tokens[] = new Token($single, $char, null, $line);
    }

    private function peek(int $offset = 0): string
    {
        $index = $this->position + $offset;

        return $index < $this->length ? $this->source[$index] : "\0";
    }

    private function advance(): string
    {
        if ($this->atEnd()) {
            return "\0";
        }

        $char = $this->source[$this->position++];

        if ($char === "\n") {
            $this->line++;
        }

        return $char;
    }

    private function atEnd(): bool
    {
        return $this->position >= $this->length;
    }
}
