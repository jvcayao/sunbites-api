<?php

namespace Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\Contract\ShapeExtractor;

class ShapeExtractorTest extends TestCase
{
    public function test_null_becomes_the_null_token(): void
    {
        $this->assertSame('null', ShapeExtractor::shapeOf(json_decode('null')));
    }

    public function test_booleans_become_the_bool_token(): void
    {
        $this->assertSame('bool', ShapeExtractor::shapeOf(json_decode('true')));
        $this->assertSame('bool', ShapeExtractor::shapeOf(json_decode('false')));
    }

    public function test_integers_and_floats_share_the_number_token(): void
    {
        $this->assertSame('number', ShapeExtractor::shapeOf(json_decode('150')));
        $this->assertSame('number', ShapeExtractor::shapeOf(json_decode('150.5')));
    }

    public function test_two_decimal_string_becomes_decimal2_not_string(): void
    {
        $this->assertSame('string:decimal2', ShapeExtractor::shapeOf('150.00'));
        $this->assertSame('string:decimal2', ShapeExtractor::shapeOf('-0.50'));
    }

    public function test_microsecond_utc_timestamp_becomes_iso8601_micro(): void
    {
        $this->assertSame('string:iso8601_micro', ShapeExtractor::shapeOf('2026-10-15T09:30:00.000000Z'));
    }

    public function test_offset_timestamp_becomes_iso8601(): void
    {
        $this->assertSame('string:iso8601', ShapeExtractor::shapeOf('2026-10-15T09:30:00+08:00'));
    }

    public function test_space_separated_timestamp_becomes_datetime(): void
    {
        $this->assertSame('string:datetime', ShapeExtractor::shapeOf('2026-10-15 09:30:00'));
    }

    public function test_calendar_date_becomes_date(): void
    {
        $this->assertSame('string:date', ShapeExtractor::shapeOf('2026-10-15'));
    }

    public function test_any_other_string_becomes_string(): void
    {
        $this->assertSame('string', ShapeExtractor::shapeOf('ANT-2026-000001'));
        $this->assertSame('string', ShapeExtractor::shapeOf('150.5'));
        $this->assertSame('string', ShapeExtractor::shapeOf(''));
    }

    public function test_empty_list_becomes_list_empty(): void
    {
        $this->assertSame('list:empty', ShapeExtractor::shapeOf(json_decode('[]')));
    }

    public function test_empty_object_stays_an_object(): void
    {
        $shape = ShapeExtractor::shapeOf(json_decode('{}'));

        $this->assertInstanceOf(stdClass::class, $shape);
        $this->assertSame('{}', json_encode($shape));
    }

    public function test_object_keys_are_sorted_so_key_order_is_ignored(): void
    {
        $first = ShapeExtractor::shapeOf(json_decode('{"b": 1, "a": "x", "c": {"z": null, "y": true}}'));
        $second = ShapeExtractor::shapeOf(json_decode('{"c": {"y": false, "z": null}, "a": "y", "b": 2}'));

        $this->assertSame(['a' => 'string', 'b' => 'number', 'c' => ['y' => 'bool', 'z' => 'null']], $first);
        $this->assertSame($first, $second);
    }

    public function test_list_with_one_element_shape_becomes_list(): void
    {
        $shape = ShapeExtractor::shapeOf(json_decode('[{"id": 1}, {"id": 2}]'));

        $this->assertSame(['__list__' => ['id' => 'number']], $shape);
    }

    public function test_mixed_list_becomes_sorted_union_of_unique_shapes(): void
    {
        $shape = ShapeExtractor::shapeOf(json_decode('[{"student": {"id": 1}}, {"student": null}, {"student": {"id": 2}}]'));

        $this->assertSame([
            '__list_union__' => [
                ['student' => 'null'],
                ['student' => ['id' => 'number']],
            ],
        ], $shape);
    }

    public function test_map_paths_collapse_data_keys_to_the_first_value_shape(): void
    {
        $json = json_decode('{"summary": {"grade_breakdown": {"Grade 1": 3, "Grade 2": 5}, "total": 8}}');

        $shape = ShapeExtractor::shapeOf($json, ['summary.grade_breakdown']);

        $this->assertSame(['summary' => ['grade_breakdown' => ['__map__' => 'number'], 'total' => 'number']], $shape);
    }

    public function test_map_paths_inside_lists_use_bracket_notation(): void
    {
        $json = json_decode('{"data": [{"categories": {"meal": {"used": 1}, "snack": {"used": 0}}}]}');

        $shape = ShapeExtractor::shapeOf($json, ['data[].categories']);

        $this->assertSame(['data' => ['__list__' => ['categories' => ['__map__' => ['used' => 'number']]]]], $shape);
    }

    public function test_associative_arrays_are_treated_as_objects(): void
    {
        $shape = ShapeExtractor::shapeOf(['b' => '1.00', 'a' => [1, 2]]);

        $this->assertSame(['a' => ['__list__' => 'number'], 'b' => 'string:decimal2'], $shape);
    }
}
