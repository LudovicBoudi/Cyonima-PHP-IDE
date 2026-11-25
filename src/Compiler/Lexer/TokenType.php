<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Lexer;

enum TokenType: string
{
    case OpenTag = 'open-tag';
    case CloseTag = 'close-tag';
    case Identifier = 'identifier';
    case Variable = 'variable';
    case Integer = 'integer';
    case Float = 'float-literal';
    case String = 'string-literal';
    case InterpolatedString = 'interpolated-string';

    case True = 'true';
    case False = 'false';
    case Null = 'null';

    case Function = 'function';
    case Return = 'return';
    case Echo = 'echo';
    case If = 'if';
    case ElseIf = 'elseif';
    case Else = 'else';
    case While = 'while';
    case For = 'for';
    case ForEach = 'foreach';
    case As = 'as';
    case Declare = 'declare';

    case IntType = 'int';
    case FloatType = 'float';
    case BoolType = 'bool';
    case StringType = 'string';
    case VoidType = 'void';

    case Plus = 'plus';
    case Minus = 'minus';
    case Star = 'star';
    case Slash = 'slash';
    case Percent = 'percent';
    case Dot = 'dot';

    case Assign = 'assign';
    case Equal = 'equal';
    case NotEqual = 'not-equal';
    case Identical = 'identical';
    case NotIdentical = 'not-identical';

    case Less = 'less';
    case LessEqual = 'less-equal';
    case Greater = 'greater';
    case GreaterEqual = 'greater-equal';

    case AndAnd = 'and-and';
    case OrOr = 'or-or';
    case Bang = 'bang';

    case LeftParen = 'left-paren';
    case RightParen = 'right-paren';
    case LeftBrace = 'left-brace';
    case RightBrace = 'right-brace';
    case LeftBracket = 'left-bracket';
    case RightBracket = 'right-bracket';
    case Comma = 'comma';
    case Semicolon = 'semicolon';
    case Colon = 'colon';
    case Arrow = 'arrow';

    case Eof = 'eof';
}
