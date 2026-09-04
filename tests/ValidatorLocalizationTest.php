<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Support\Lang;
use Plugins\I18n\Translator;
use Plugins\Validation\Validator;

/**
 * Validation messages follow the request's locale, and field names read as
 * prose rather than as wire keys.
 *
 * The behaviour under test is a FALLBACK, which is the part that had been
 * missing: the Translator parameter existed from the start, but it was optional
 * and threaded through six signatures, and not one call site in any first-party
 * plugin passed it — so every validation message in the platform was English
 * regardless of locale. These tests pin the discovery, not the parameter.
 */
#[CoversClass(Validator::class)]
final class ValidatorLocalizationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hkm-val-i18n-' . bin2hex(random_bytes(6));

        $catalogue = [
            'en' => [
                'required'   => 'The :field field is required.',
                'min'        => 'The :field field must be at least :min.',
                'attributes' => ['firstName' => 'first name'],
            ],
            'fr' => [
                'required'   => 'Le champ :field est obligatoire.',
                'min'        => 'Le champ :field doit être au moins :min.',
                'attributes' => ['firstName' => 'prénom'],
            ],
        ];

        foreach ($catalogue as $locale => $lines) {
            @mkdir($this->dir . '/' . $locale, 0777, true);
            file_put_contents(
                $this->dir . '/' . $locale . '/validation.php',
                '<?php return ' . var_export($lines, true) . ';',
            );
        }
    }

    protected function tearDown(): void
    {
        Lang::clear();

        foreach (glob($this->dir . '/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $sub) {
            is_dir($sub) && rmdir($sub);
        }
        is_dir($this->dir) && rmdir($this->dir);
    }

    private function bind(string $locale): void
    {
        Lang::bind(new Translator($this->dir, $locale, 'en'));
    }

    /** @return list<string> */
    private function errorsFor(string $field, array $rules, array $data = []): array
    {
        return Validator::make($data, $rules)->errors()[$field] ?? [];
    }

    // --- Translator discovery -------------------------------------------------

    public function test_messages_use_the_request_locale_without_any_call_site_opting_in(): void
    {
        $this->bind('fr');

        $this->assertSame(
            ['Le champ prénom est obligatoire.'],
            $this->errorsFor('firstName', ['firstName' => 'required']),
        );
    }

    public function test_the_same_rules_produce_english_under_an_english_locale(): void
    {
        $this->bind('en');

        $this->assertSame(
            ['The first name field is required.'],
            $this->errorsFor('firstName', ['firstName' => 'required']),
        );
    }

    public function test_an_explicitly_injected_translator_wins_over_the_bound_one(): void
    {
        // A caller that passed a translator meant THAT one.
        $this->bind('en');

        $errors = Validator::make(
            [],
            ['firstName' => 'required'],
            [],
            new Translator($this->dir, 'fr', 'en'),
        )->errors();

        $this->assertSame(['Le champ prénom est obligatoire.'], $errors['firstName']);
    }

    public function test_it_degrades_to_english_defaults_when_no_translator_is_bound(): void
    {
        // CLI, workers, or a project without I18n installed. The point of the
        // fallback is that adding a catalogue can only improve output, so its
        // absence must not make anything worse than it was.
        Lang::clear();

        $this->assertSame(
            ['The email field is required.'],
            $this->errorsFor('email', ['email' => 'required']),
        );
    }

    // --- Field names ----------------------------------------------------------

    public function test_a_field_name_is_read_from_the_attributes_catalogue(): void
    {
        $this->bind('fr');

        $this->assertStringContainsString('prénom', $this->errorsFor('firstName', ['firstName' => 'required'])[0]);
    }

    public function test_an_unlisted_field_name_is_humanised_rather_than_shown_raw(): void
    {
        $this->bind('en');

        // No `postalCode` entry in attributes — camelCase must still not reach a reader.
        $this->assertSame(
            ['The postal code field is required.'],
            $this->errorsFor('postalCode', ['postalCode' => 'required']),
        );
    }

    public function test_snake_and_kebab_field_names_are_humanised_too(): void
    {
        $this->bind('en');

        $this->assertSame(
            ['The postal code field is required.'],
            $this->errorsFor('postal_code', ['postal_code' => 'required']),
        );
    }

    public function test_the_other_field_in_a_same_rule_is_humanised(): void
    {
        // :other names a FIELD, so it gets the same treatment as :field —
        // otherwise a wire name leaks into copy the reader is meant to act on.
        Lang::clear();

        $errors = Validator::make(
            ['password' => 'a', 'passwordConfirmation' => 'b'],
            ['password' => 'same:passwordConfirmation'],
        )->errors();

        $this->assertSame(['The password field must match password confirmation.'], $errors['password']);
    }

    // --- Rule parameters ------------------------------------------------------

    public function test_a_custom_rule_can_reach_its_own_parameter(): void
    {
        // :param is what a rule registered through extend()/extendWith() has to
        // use — it has no entry in the built-in replacement table. Without it
        // every CommonRules line written that way ("must be a multiple of
        // :param") printed the placeholder to the user, literally.
        Lang::clear();
        Validator::flushExtensions();
        Validator::extend(
            'multiple_of',
            static fn(mixed $v, ?string $p): bool => false,
            'The :field field must be a multiple of :param.',
        );

        $errors = Validator::make(['n' => 7], ['n' => 'multiple_of:5'])->errors();
        Validator::flushExtensions();

        $this->assertSame(['The n field must be a multiple of 5.'], $errors['n']);
    }

    public function test_a_built_in_rule_keeps_its_own_placeholder_name(): void
    {
        // :param is added first so the readable names still win where they exist.
        Lang::clear();

        $this->assertSame(
            ['The n field must be between 2 and 4.'],
            $this->errorsFor('n', ['n' => 'between:2,4'], ['n' => 9]),
        );
    }

    // --- Interpolation --------------------------------------------------------

    public function test_rule_parameters_still_interpolate_in_a_translated_message(): void
    {
        $this->bind('fr');

        $this->assertSame(
            ['Le champ prénom doit être au moins 3.'],
            $this->errorsFor('firstName', ['firstName' => 'min:3'], ['firstName' => 'ab']),
        );
    }

    public function test_a_short_placeholder_does_not_corrupt_a_longer_one(): void
    {
        // :min must not eat the ":min" inside ":minutes" — the defaults are
        // interpolated in one longest-key-first pass for exactly this reason.
        Lang::clear();
        Validator::flushExtensions();
        Validator::extend(
            'window',
            static fn(mixed $v, ?string $p): bool => false,
            'Wait :minutes minutes (at least :min).',
        );

        $errors = Validator::make(['x' => 1], ['x' => 'window'], [])->errors();
        Validator::flushExtensions();

        $this->assertSame(['Wait :minutes minutes (at least :min).'], $errors['x']);
    }
}
