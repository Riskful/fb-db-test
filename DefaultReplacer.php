<?php
declare(strict_types=1);

namespace FpDbTest;

use Exception;
use mysqli;

class DefaultReplacer
{
    private array $matches = [];
    private array $mapArgs = [];

    public function __construct(
        readonly private array $args,
        readonly private string $query,
        readonly private mysqli $mysqli
    ) {}

    public function addMatcher(string $regex, string $matcher): void
    {
        $this->matches[$regex] = [$this, $matcher];
    }

    /**
     * @throws Exception
     */
    public function getResult(): string
    {
        $this->validateSpecifiers();
        $this->mappingArgs();
        $replacedQuery = preg_replace_callback_array($this->getPatternReplace(), $this->query);

        return $this->conditions($replacedQuery);
    }

    /**
     * @throws Exception
     */
    public function integer(array $match): string|int
    {
        $value = array_shift($this->mapArgs[$match[0]]);

        if (is_int($value) || is_bool($value)) {
            return (int) $value;
        } elseif (is_null($value)) {
            return 'NULL';
        }

        $this->exceptionValue($match[0]);
    }

    /**
     * @throws Exception
     */
    public function ids(array $match): string|array
    {
        $value = array_shift($this->mapArgs[$match[0]]);

        if (is_array($value)) {
            $identifiers = array_map([$this->mysqli, 'real_escape_string'], $value);
            $formattedIdentifiers = array_map(fn($id) => "`$id`", $identifiers);

            return implode(', ', $formattedIdentifiers);
        }

        if ($value) {
            $prepareValue = $this->mysqli->real_escape_string($value);
            return "`$prepareValue`";
        }

        $this->exceptionValue($match[0]);
    }

    /**
     * @throws Exception
     */
    public function float(array $match): string|float
    {
        $value = array_shift($this->mapArgs[$match[0]]);

        if (is_float($value)) {
            return $value;
        } elseif (is_null($value)) {
            return 'NULL';
        }

        $this->exceptionValue($match[0]);
    }

    /**
     * @throws Exception
     */
    public function array(array $match): string
    {
        $value = array_shift($this->mapArgs[$match[0]]);

        if (is_array($value)) {
            $formatted = array_map(function ($key, $arg) {
                return is_int($key) ? $this->prepare($arg) : "`$key` = " . $this->prepare($arg);
            }, array_keys($value), $value);

            return implode(', ', $formatted);
        }

        $this->exceptionValue($match[0]);
    }

    /**
     * @throws Exception
     */
    public function any(array $match): mixed
    {
        $value = array_shift($this->mapArgs[$match[0]]);

        return $this->prepare($value);
    }

    public function conditions(string $query): string
    {
        preg_match_all('/\{[^{}]*}/', $query, $matches);
        $filteredQuery = $query;

        foreach ($matches[0] as $match) {
            if (str_contains($match, 'NULL')) {
                $filteredQuery = str_replace($match, '', $filteredQuery);
            }
        }

        return str_replace(['{', '}'], '', $filteredQuery);
    }

    /**
     * @throws Exception
     */
    private function validateSpecifiers(): void
    {
        preg_match_all('~\?+[.\S]~i', $this->query, $matches);
        $invalidSpecifiers = array_diff($matches[0], array_keys($this->matches));

        if ($invalidSpecifiers) {
            throw new Exception(
                sprintf('Invalid specifier(s): %s.', implode(', ', $invalidSpecifiers))
            );
        };
    }

    private function getPatternReplace(): array
    {
        $pattern = [];

        foreach ($this->matches as $regex => $callback) {
            $pattern[sprintf('~\%s~i', $regex)] = $callback;
        }

        return $pattern;
    }

    /**
     * @throws Exception
     */
    private function prepare($arg): string|int|float
    {
        $validTypes = ['string', 'integer', 'float', 'bool', 'NULL'];

        if (!in_array(gettype($arg), $validTypes)) {
            throw new Exception(sprintf('Invalid type argument: %s.', gettype($arg)));
        }

        if (is_null($arg)) {
            return 'NULL';
        } elseif (is_bool($arg)) {
            return (int) $arg;
        } elseif (is_int($arg) || is_float($arg)) {
            return $arg;
        }

        $prepareArg = $this->mysqli->real_escape_string($arg);

        return "'$prepareArg'";
    }

    private function mappingArgs(): void
    {
        $regexes = array_map(fn($regex) => '\\' . $regex, array_keys($this->matches));
        preg_match_all('/' . implode('|', $regexes) . '/i', $this->query, $matches);

        foreach ($matches[0] as $index => $value) {
            $this->mapArgs[$value][] = $this->args[$index];
        }
    }

    /**
     * @throws Exception
     */
    private function exceptionValue(string $specifier): void
    {
        throw new Exception(sprintf('Invalid value %s specifier', $specifier));
    }
}