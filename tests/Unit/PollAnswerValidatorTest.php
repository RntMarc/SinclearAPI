<?php

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Poll\PollAnswerValidator;

class PollAnswerValidatorTest extends TestCase
{
    private PollAnswerValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PollAnswerValidator();
    }

    private function question(string $type, array $config = [], bool $required = false): array
    {
        return ['type' => $type, 'config' => $config, 'isRequired' => $required];
    }

    // ── text / textarea ──────────────────────────────────

    public function testTextTrimsValue(): void
    {
        $this->assertSame('hello', $this->validator->validate($this->question('text'), '  hello  '));
    }

    public function testTextRespectsMaxLength(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('text', ['maxLength' => 3]), 'abcd');
    }

    public function testTextRespectsMinLength(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('text', ['minLength' => 5]), 'abc');
    }

    public function testTextareaValid(): void
    {
        $this->assertSame("line1\nline2", $this->validator->validate($this->question('textarea'), "line1\nline2"));
    }

    // ── number ───────────────────────────────────────────

    public function testNumberValid(): void
    {
        $this->assertSame('42', $this->validator->validate($this->question('number'), '42'));
    }

    public function testNumberIntegerOnlyRejectsFraction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('number', ['integerOnly' => true]), '4.5');
    }

    public function testNumberRespectsRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('number', ['min' => 1, 'max' => 10]), '11');
    }

    public function testNumberRejectsNonNumeric(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('number'), 'abc');
    }

    // ── email ────────────────────────────────────────────

    public function testEmailValid(): void
    {
        $this->assertSame('a@b.com', $this->validator->validate($this->question('email'), 'a@b.com'));
    }

    public function testEmailInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('email'), 'not-an-email');
    }

    // ── coordinates ──────────────────────────────────────

    public function testCoordinatesFromArray(): void
    {
        $this->assertSame('52.52,13.405', $this->validator->validate($this->question('coordinates'), ['lat' => 52.52, 'lon' => 13.405]));
    }

    public function testCoordinatesFromString(): void
    {
        $this->assertSame('52.52,13.405', $this->validator->validate($this->question('coordinates'), '52.52,13.405'));
    }

    public function testCoordinatesOutOfRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('coordinates'), '91,13');
    }

    public function testCoordinatesInvalidShape(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('coordinates'), '52.52');
    }

    // ── date / datetime ──────────────────────────────────

    public function testDateValid(): void
    {
        $this->assertSame('2026-09-26', $this->validator->validate($this->question('date'), '2026-09-26'));
    }

    public function testDateInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('date'), '26.09.2026');
    }

    public function testDateRespectsRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('date', ['minDate' => '2026-01-01']), '2025-12-31');
    }

    public function testDateTimeValidStoresUtc(): void
    {
        $result = $this->validator->validate(
            $this->question('datetime', ['timezone' => 'Europe/Berlin']),
            '2026-09-26T14:30:00+02:00',
        );
        $this->assertSame('2026-09-26 12:30:00', $result);
    }

    public function testDateTimeRequiresOffset(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('datetime'), '2026-09-26 14:30:00');
    }

    // ── url ──────────────────────────────────────────────

    public function testUrlValid(): void
    {
        $this->assertSame('https://example.com', $this->validator->validate($this->question('url'), 'https://example.com'));
    }

    public function testUrlRejectsDisallowedScheme(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('url', ['allowedSchemes' => ['https']]), 'http://example.com');
    }

    public function testUrlInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('url'), 'not a url');
    }

    // ── phone ────────────────────────────────────────────

    public function testPhoneValid(): void
    {
        $this->assertSame('+491234567890', $this->validator->validate($this->question('phone'), '+49 1234 567890'));
    }

    public function testPhoneInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('phone'), 'abc');
    }

    // ── single_choice ────────────────────────────────────

    public function testSingleChoiceValid(): void
    {
        $this->assertSame('opt-1', $this->validator->validate($this->question('single_choice'), 'opt-1', ['opt-1', 'opt-2']));
    }

    public function testSingleChoiceUnknownOptionRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('single_choice'), 'opt-9', ['opt-1']);
    }

    public function testSingleChoiceAllowOther(): void
    {
        $this->assertSame('Freitext', $this->validator->validate($this->question('single_choice', ['allowOther' => true]), 'Freitext', ['opt-1']));
    }

    // ── multiple_choice ──────────────────────────────────

    public function testMultipleChoiceValid(): void
    {
        $result = $this->validator->validate($this->question('multiple_choice'), ['opt-1', 'opt-2'], ['opt-1', 'opt-2', 'opt-3']);
        $this->assertSame(['opt-1', 'opt-2'], json_decode((string) $result, true));
    }

    public function testMultipleChoiceRejectsUnknownOption(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('multiple_choice'), ['opt-9'], ['opt-1']);
    }

    public function testMultipleChoiceRespectsMaxSelected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('multiple_choice', ['maxSelected' => 1]), ['opt-1', 'opt-2'], ['opt-1', 'opt-2']);
    }

    public function testMultipleChoiceRespectsMinSelected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('multiple_choice', ['minSelected' => 2]), ['opt-1'], ['opt-1', 'opt-2']);
    }

    // ── boolean ──────────────────────────────────────────

    public function testBooleanTrue(): void
    {
        $this->assertSame('1', $this->validator->validate($this->question('boolean'), true));
    }

    public function testBooleanFalse(): void
    {
        $this->assertSame('0', $this->validator->validate($this->question('boolean'), false));
    }

    public function testBooleanString(): void
    {
        $this->assertSame('1', $this->validator->validate($this->question('boolean'), 'ja'));
    }

    public function testBooleanInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('boolean'), 'maybe');
    }

    // ── rating ───────────────────────────────────────────

    public function testRatingValid(): void
    {
        $this->assertSame('4', $this->validator->validate($this->question('rating', ['min' => 1, 'max' => 5]), 4));
    }

    public function testRatingRespectsRange(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('rating', ['min' => 1, 'max' => 5]), 6);
    }

    public function testRatingRespectsStep(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('rating', ['min' => 0, 'max' => 5, 'step' => 1]), 2.5);
    }

    // ── required / optional ──────────────────────────────

    public function testRequiredRejectsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('text', [], true), '   ');
    }

    public function testOptionalEmptyReturnsNull(): void
    {
        $this->assertNull($this->validator->validate($this->question('text'), ''));
        $this->assertNull($this->validator->validate($this->question('multiple_choice'), []));
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->validator->validate($this->question('unknown'), 'x');
    }
}
