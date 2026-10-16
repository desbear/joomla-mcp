<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcp
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcp\Administrator\Mcp;

\defined('_JEXEC') or die;

/**
 * Minimal JSON schema check and coercion for tool arguments.
 *
 * Models occasionally send "12" for an integer or "true" for a boolean; those are coerced
 * instead of rejected. Anything else that does not match produces a ToolException that tells
 * the model what was expected.
 *
 * @since  0.1.0
 */
final class ArgumentValidator
{
    /**
     * Most entries accepted in any array argument, whatever the schema says. Handlers do per entry
     * database work, so an unbounded list is an unbounded amount of work for one call.
     */
    public const MAX_ITEMS = 500;

    /** Most keys accepted in any object argument. */
    public const MAX_PROPERTIES = 100;

    /** Longest string accepted in any argument (article bodies need room). */
    public const MAX_STRING = 2000000;

    public static function validate(array $arguments, array $schema): array
    {
        $properties = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (!\array_key_exists($required, $arguments) || $arguments[$required] === null || $arguments[$required] === '') {
                throw new ToolException(\sprintf('Missing required argument "%s".', $required));
            }
        }

        if (\count($arguments) > self::MAX_PROPERTIES) {
            throw new ToolException(\sprintf('Too many arguments: at most %d are accepted.', self::MAX_PROPERTIES));
        }

        foreach ($arguments as $name => $value) {
            if (!isset($properties[$name])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    throw new ToolException(\sprintf(
                        'Unknown argument "%s". Valid arguments: %s.',
                        $name,
                        implode(', ', array_keys($properties))
                    ));
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            $arguments[$name] = self::coerce($name, $value, (array) $properties[$name]);
        }

        return $arguments;
    }

    private static function coerce(string $name, mixed $value, array $schema): mixed
    {
        $types = (array) ($schema['type'] ?? []);

        if ($types) {
            $matched = false;

            foreach ($types as $type) {
                [$ok, $coerced] = self::coerceType($value, $type);

                if ($ok) {
                    $value   = $coerced;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                throw new ToolException(\sprintf('Argument "%s" must be of type %s.', $name, implode(' or ', $types)));
            }
        }

        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            throw new ToolException(\sprintf(
                'Argument "%s" must be one of: %s.',
                $name,
                implode(', ', array_map('strval', $schema['enum']))
            ));
        }

        if (\is_string($value) && \strlen($value) > (int) ($schema['maxLength'] ?? self::MAX_STRING)) {
            throw new ToolException(\sprintf('Argument "%s" is too long.', $name));
        }

        if (is_numeric($value) && !\is_bool($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new ToolException(\sprintf('Argument "%s" must be %s or more.', $name, $schema['minimum']));
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new ToolException(\sprintf('Argument "%s" must be %s or less.', $name, $schema['maximum']));
            }
        }

        if (\is_array($value) && array_is_list($value)) {
            $max = min((int) ($schema['maxItems'] ?? self::MAX_ITEMS), self::MAX_ITEMS);

            if (\count($value) > $max) {
                throw new ToolException(\sprintf('Argument "%s" takes at most %d entries; send the rest in another call.', $name, $max));
            }

            if (isset($schema['minItems']) && \count($value) < (int) $schema['minItems']) {
                throw new ToolException(\sprintf('Argument "%s" needs at least %d entries.', $name, (int) $schema['minItems']));
            }

            if (isset($schema['items'])) {
                foreach ($value as $i => $item) {
                    $value[$i] = self::coerce($name . '[' . $i . ']', $item, (array) $schema['items']);
                }
            }
        }

        // Nested objects: validate them with the same rules rather than accepting them wholesale,
        // so a nested "enum" or "required" is enforced here and not only by the handler
        if (\is_array($value) && !array_is_list($value)) {
            if (\count($value) > self::MAX_PROPERTIES) {
                throw new ToolException(\sprintf('Argument "%s" has too many keys; at most %d are accepted.', $name, self::MAX_PROPERTIES));
            }

            if (isset($schema['properties'])) {
                $value = self::validate($value, $schema);
            }
        }

        return $value;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private static function coerceType(mixed $value, string $type): array
    {
        switch ($type) {
            case 'string':
                if (\is_string($value)) {
                    return [true, $value];
                }

                return (\is_int($value) || \is_float($value)) ? [true, (string) $value] : [false, null];

            case 'integer':
                if (\is_int($value)) {
                    return [true, $value];
                }

                if (\is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
                    return [true, (int) $value];
                }

                return (\is_float($value) && floor($value) == $value) ? [true, (int) $value] : [false, null];

            case 'number':
                return is_numeric($value) ? [true, $value + 0] : [false, null];

            case 'boolean':
                if (\is_bool($value)) {
                    return [true, $value];
                }

                if ($value === 1 || $value === 0) {
                    return [true, (bool) $value];
                }

                if (\is_string($value) && \in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
                    return [true, \in_array(strtolower($value), ['true', '1'], true)];
                }

                return [false, null];

            case 'array':
                if (\is_array($value) && array_is_list($value)) {
                    return [true, $value];
                }

                // A single scalar where a list is expected: wrap it
                return is_scalar($value) ? [true, [$value]] : [false, null];

            case 'object':
                return (\is_array($value) && ($value === [] || !array_is_list($value))) ? [true, $value] : [false, null];

            case 'null':
                return [$value === null, null];
        }

        return [true, $value];
    }
}
