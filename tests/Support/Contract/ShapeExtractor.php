<?php

namespace Tests\Support\Contract;

use InvalidArgumentException;
use stdClass;

/**
 * Reduces a decoded JSON body to its shape: keys, JSON types and string formats, never values.
 *
 * The grammar is fixed by Spec 16 design §4.1.1. A shape is what the frontends depend on, so a
 * Track A refactor must leave every recorded shape unchanged.
 */
class ShapeExtractor
{
    public const string TOKEN_NULL = 'null';

    public const string TOKEN_BOOL = 'bool';

    public const string TOKEN_NUMBER = 'number';

    public const string TOKEN_DECIMAL2 = 'string:decimal2';

    public const string TOKEN_ISO8601_MICRO = 'string:iso8601_micro';

    public const string TOKEN_ISO8601 = 'string:iso8601';

    public const string TOKEN_DATETIME = 'string:datetime';

    public const string TOKEN_DATE = 'string:date';

    public const string TOKEN_STRING = 'string';

    public const string TOKEN_EMPTY_LIST = 'list:empty';

    public const string LIST_KEY = '__list__';

    public const string LIST_UNION_KEY = '__list_union__';

    public const string MAP_KEY = '__map__';

    /**
     * String formats, checked in this order. Decimal detection runs before date detection.
     *
     * @var array<string, string>
     */
    private const array STRING_FORMATS = [
        self::TOKEN_DECIMAL2 => '/^-?\d+\.\d{2}$/',
        self::TOKEN_ISO8601_MICRO => '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/',
        self::TOKEN_ISO8601 => '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/',
        self::TOKEN_DATETIME => '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',
        self::TOKEN_DATE => '/^\d{4}-\d\d-\d\d$/',
    ];

    /**
     * Objects may arrive as `stdClass` (`json_decode($json)`) or as associative arrays
     * (`json_decode($json, true)`). Only `stdClass` can tell an empty object from an empty
     * list, so contract assertions decode with objects.
     *
     * Paths use dots between object keys and `[]` for list elements, e.g. `data[].categories`.
     *
     * @param  list<string>  $mapPaths  object paths whose keys are data, not schema
     */
    public static function shapeOf(mixed $decodedJson, array $mapPaths = [], string $path = ''): mixed
    {
        if ($decodedJson === null) {
            return self::TOKEN_NULL;
        }

        if (is_bool($decodedJson)) {
            return self::TOKEN_BOOL;
        }

        if (is_int($decodedJson) || is_float($decodedJson)) {
            return self::TOKEN_NUMBER;
        }

        if (is_string($decodedJson)) {
            return self::stringShape($decodedJson);
        }

        if ($decodedJson instanceof stdClass) {
            return self::objectShape(get_object_vars($decodedJson), $mapPaths, $path);
        }

        if (is_array($decodedJson) && ! array_is_list($decodedJson)) {
            return self::objectShape($decodedJson, $mapPaths, $path);
        }

        if (is_array($decodedJson)) {
            return self::listShape($decodedJson, $mapPaths, $path);
        }

        throw new InvalidArgumentException('Unsupported JSON value of type '.get_debug_type($decodedJson).'.');
    }

    private static function stringShape(string $value): string
    {
        foreach (self::STRING_FORMATS as $token => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $token;
            }
        }

        return self::TOKEN_STRING;
    }

    /**
     * An empty object becomes `stdClass` so that it encodes as `{}` in a fixture.
     *
     * @param  array<array-key, mixed>  $properties
     * @param  list<string>  $mapPaths
     */
    private static function objectShape(array $properties, array $mapPaths, string $path): mixed
    {
        if ($properties === []) {
            return new stdClass;
        }

        if (in_array($path, $mapPaths, true)) {
            $firstValue = reset($properties);

            return [self::MAP_KEY => self::shapeOf($firstValue, $mapPaths, $path.'.'.self::MAP_KEY)];
        }

        $shape = [];

        foreach ($properties as $key => $value) {
            $key = (string) $key;
            $shape[$key] = self::shapeOf($value, $mapPaths, $path === '' ? $key : $path.'.'.$key);
        }

        ksort($shape, SORT_STRING);

        return $shape;
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $mapPaths
     */
    private static function listShape(array $items, array $mapPaths, string $path): mixed
    {
        if ($items === []) {
            return self::TOKEN_EMPTY_LIST;
        }

        $uniqueShapes = [];

        foreach ($items as $item) {
            $shape = self::shapeOf($item, $mapPaths, $path.'[]');
            $uniqueShapes[self::encode($shape)] = $shape;
        }

        if (count($uniqueShapes) === 1) {
            return [self::LIST_KEY => reset($uniqueShapes)];
        }

        ksort($uniqueShapes, SORT_STRING);

        return [self::LIST_UNION_KEY => array_values($uniqueShapes)];
    }

    /**
     * A canonical encoding, used both to de-duplicate list shapes and to sort a union.
     */
    public static function encode(mixed $shape): string
    {
        return json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
