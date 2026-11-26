<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

use Cyonima\Compiler\CompilerException;
use Cyonima\Compiler\Lexer\Token;
use Cyonima\Compiler\Lexer\TokenType;
use Cyonima\Compiler\Semantic\FunctionInfo;

/**
 * Registre extensible des modules natifs connus du compilateur.
 */
final class NativeModuleRegistry
{
    /** @return array<string, NativeModule> */
    public function all(): array
    {
        return [
            'sqlite' => new SqliteModule(),
            'curl' => new CurlModule(),
            'ssh2' => new Ssh2Module(),
        ];
    }

    /**
     * @param list<string> $requested
     * @return array<string, NativeModule>
     */
    public function resolve(array $requested): array
    {
        $all = $this->all();
        $modules = [];

        foreach ($requested as $name) {
            $module = $all[$name] ?? throw new CompilerException(
                'module natif inconnu : ' . $name,
            );
            $modules[$name] = $module;
        }

        return $modules;
    }

    /**
     * Active un module si une de ses fonctions est appelée dans le source.
     *
     * @param list<Token> $tokens
     * @param list<string> $requested
     * @return array<string, NativeModule>
     */
    public function activeForTokens(array $tokens, array $requested = []): array
    {
        $modules = $this->resolve($requested);
        $count = count($tokens);

        foreach ($this->all() as $name => $module) {
            if (isset($modules[$name])) {
                continue;
            }

            $functions = array_keys($module->functions());

            for ($index = 0; $index < $count - 1; $index++) {
                $token = $tokens[$index];

                if ($token->type !== TokenType::Identifier
                    || !in_array($token->literal, $functions, true)
                    || $tokens[$index + 1]->type !== TokenType::LeftParen) {
                    continue;
                }

                $previous = $index > 0 ? $tokens[$index - 1]->type : null;

                if ($previous === TokenType::Function
                    || $previous === TokenType::ObjectArrow
                    || $previous === TokenType::DoubleColon
                    || $previous === TokenType::NewKeyword) {
                    continue;
                }

                $modules[$name] = $module;
                break;
            }
        }

        return $modules;
    }

    /**
     * @param array<string, NativeModule> $modules
     * @return array<string, FunctionInfo>
     */
    public function functions(array $modules): array
    {
        $functions = [];

        foreach ($modules as $module) {
            foreach ($module->functions() as $function) {
                if (isset($functions[$function->name])) {
                    throw new CompilerException(
                        'fonction native dupliquée : ' . $function->name,
                    );
                }

                $functions[$function->name] = new FunctionInfo(
                    $function->name,
                    $function->params,
                    $function->returnType,
                );
            }
        }

        return $functions;
    }
}
