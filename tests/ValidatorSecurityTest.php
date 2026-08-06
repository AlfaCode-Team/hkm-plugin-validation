<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Validation\Validator;

/**
 * Regression cover for V-01 (unknown rules silently passed — a validation
 * bypass) and V-02 (a regex containing '|' was torn in half by the splitter).
 */
#[CoversClass(Validator::class)]
final class ValidatorSecurityTest extends TestCase
{
    // ── V-01 ────────────────────────────────────────────────────────────────

    public function test_a_mistyped_rule_name_throws_instead_of_passing(): void
    {
        // 'emial' used to pass silently, leaving the field completely
        // unvalidated in production.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Unknown validation rule \[emial\]/');

        Validator::make(['contact' => 'not-an-email'], ['contact' => 'emial'])->errors();
    }

    public function test_the_error_names_the_rule_and_how_to_register_it(): void
    {
        try {
            Validator::make(['x' => '1'], ['x' => 'definitely_not_a_rule'])->errors();
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('definitely_not_a_rule', $e->getMessage());
            self::assertStringContainsString('Validator::extend', $e->getMessage());
        }
    }

    public function test_built_in_rules_still_work(): void
    {
        self::assertSame([], Validator::make(['email' => 'a@b.test'], ['email' => 'required|email'])->errors());
        self::assertNotSame([], Validator::make(['email' => 'nope'], ['email' => 'required|email'])->errors());
    }

    // ── V-02 ────────────────────────────────────────────────────────────────

    public function test_a_regex_containing_alternation_survives_the_split(): void
    {
        // Previously exploded into 'regex:/^(cat' and 'dog)$/', so the rule
        // matched nothing it was written to match — and 'dog)$/' became an
        // unknown rule that silently passed.
        self::assertSame([], Validator::make(['pet' => 'cat'], ['pet' => 'regex:/^(cat|dog)$/'])->errors());
        self::assertSame([], Validator::make(['pet' => 'dog'], ['pet' => 'regex:/^(cat|dog)$/'])->errors());
        self::assertNotSame([], Validator::make(['pet' => 'fish'], ['pet' => 'regex:/^(cat|dog)$/'])->errors());
    }

    public function test_a_regex_with_alternation_composes_with_other_rules(): void
    {
        self::assertSame(
            [],
            Validator::make(['pet' => 'dog'], ['pet' => 'required|regex:/^(cat|dog)$/|max:10'])->errors(),
        );
        self::assertNotSame(
            [],
            Validator::make(['pet' => 'fish'], ['pet' => 'required|regex:/^(cat|dog)$/|max:10'])->errors(),
        );
    }

    public function test_an_escaped_delimiter_inside_the_pattern_is_not_a_terminator(): void
    {
        self::assertSame([], Validator::make(['p' => 'a/b'], ['p' => 'regex:/^a\/b$/'])->errors());
    }

    public function test_the_array_rule_form_is_unaffected(): void
    {
        self::assertSame([], Validator::make(['pet' => 'cat'], ['pet' => ['required', 'regex:/^(cat|dog)$/']])->errors());
    }
}
