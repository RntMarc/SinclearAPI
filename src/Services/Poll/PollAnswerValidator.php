<?php

namespace Sinclear\Api\Services\Poll;

use Sinclear\Api\Support\DateTimeValue;

/**
 * Validiert und normalisiert Antworten für alle 13 Fragetypen.
 *
 * Die Klasse ist bewusst frei von DB-Abhängigkeiten und damit unit-testbar.
 * Für Auswahl-Fragetypen liefert der Aufrufer die erlaubten Option-IDs mit.
 */
final class PollAnswerValidator
{
    public const array SUPPORTED_TYPES = [
        'text', 'textarea', 'number', 'email', 'coordinates', 'date', 'datetime',
        'url', 'phone', 'single_choice', 'multiple_choice', 'boolean', 'rating',
    ];

    private const int TEXT_DEFAULT_MAX = 65535;

    public function supports(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    /**
     * Prüft einen Wert gegen die Frage und liefert den zu speichernden
     * String (oder null, wenn optional und leer).
     *
     * @param array<string, mixed> $question
     * @param string[] $optionIds erlaubte Option-IDs der Frage
     * @throws \RuntimeException bei ungültigen Werten (`invalid_answer`, `answer_required`)
     */
    public function validate(array $question, mixed $value, array $optionIds = []): ?string
    {
        $type = (string) ($question['type'] ?? '');
        if (!$this->supports($type)) {
            throw new \RuntimeException('invalid_answer');
        }

        $config = is_array($question['config'] ?? null) ? $question['config'] : [];
        $isRequired = (bool) ($question['isRequired'] ?? false);

        if ($this->isEmpty($value)) {
            if ($isRequired) {
                throw new \RuntimeException('answer_required');
            }
            return null;
        }

        return match ($type) {
            'text', 'textarea' => $this->validateText($value, $config),
            'number' => $this->validateNumber($value, $config),
            'email' => $this->validateEmail($value),
            'coordinates' => $this->validateCoordinates($value, $config),
            'date' => $this->validateDate($value, $config),
            'datetime' => $this->validateDateTime($value, $config),
            'url' => $this->validateUrl($value, $config),
            'phone' => $this->validatePhone($value),
            'single_choice' => $this->validateSingleChoice($value, $config, $optionIds),
            'multiple_choice' => $this->validateMultipleChoice($value, $config, $optionIds),
            'boolean' => $this->validateBoolean($value),
            'rating' => $this->validateRating($value, $config),
            default => throw new \RuntimeException('invalid_answer'),
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function validateText(mixed $value, array $config): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }
        $text = trim($value);

        $min = isset($config['minLength']) ? (int) $config['minLength'] : null;
        $max = isset($config['maxLength']) ? (int) $config['maxLength'] : self::TEXT_DEFAULT_MAX;

        $length = mb_strlen($text);
        if ($min !== null && $length < $min) {
            throw new \RuntimeException('invalid_answer');
        }
        if ($max > 0 && $length > $max) {
            throw new \RuntimeException('invalid_answer');
        }

        return $text;
    }

    /** @param array<string, mixed> $config */
    private function validateNumber(mixed $value, array $config): string
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('invalid_answer');
        }

        $number = (float) $value;
        $integerOnly = (bool) ($config['integerOnly'] ?? false);
        if ($integerOnly && floor($number) !== $number) {
            throw new \RuntimeException('invalid_answer');
        }

        if (isset($config['min']) && $number < (float) $config['min']) {
            throw new \RuntimeException('invalid_answer');
        }
        if (isset($config['max']) && $number > (float) $config['max']) {
            throw new \RuntimeException('invalid_answer');
        }

        return $integerOnly ? (string) (int) $number : (string) $number;
    }

    private function validateEmail(mixed $value): string
    {
        if (!is_string($value) || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('invalid_answer');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $config */
    private function validateCoordinates(mixed $value, array $config): string
    {
        $lat = null;
        $lon = null;

        if (is_array($value)) {
            $lat = $value['lat'] ?? $value[0] ?? null;
            $lon = $value['lon'] ?? $value[1] ?? null;
        } elseif (is_string($value)) {
            $parts = array_map('trim', explode(',', $value));
            if (count($parts) !== 2) {
                throw new \RuntimeException('invalid_answer');
            }
            [$lat, $lon] = $parts;
        }

        $requireBoth = (bool) ($config['requireBothFields'] ?? true);
        if ($requireBoth && ($lat === null || $lon === null)) {
            throw new \RuntimeException('invalid_answer');
        }
        if ($lat === null || $lon === null) {
            throw new \RuntimeException('invalid_answer');
        }

        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw new \RuntimeException('invalid_answer');
        }

        $latF = (float) $lat;
        $lonF = (float) $lon;
        if ($latF < -90 || $latF > 90 || $lonF < -180 || $lonF > 180) {
            throw new \RuntimeException('invalid_answer');
        }

        return $latF . ',' . $lonF;
    }

    /** @param array<string, mixed> $config */
    private function validateDate(mixed $value, array $config): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }

        try {
            $date = DateTimeValue::parseDate($value);
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('invalid_answer');
        }

        $formatted = $date->format('Y-m-d');
        if (isset($config['minDate']) && $formatted < (string) $config['minDate']) {
            throw new \RuntimeException('invalid_answer');
        }
        if (isset($config['maxDate']) && $formatted > (string) $config['maxDate']) {
            throw new \RuntimeException('invalid_answer');
        }

        return $formatted;
    }

    /** @param array<string, mixed> $config */
    private function validateDateTime(mixed $value, array $config): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }

        try {
            $instant = DateTimeValue::parseInstant($value);
            if (isset($config['timezone']) && (string) $config['timezone'] !== '') {
                DateTimeValue::assertTimeZone((string) $config['timezone']);
            }
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('invalid_answer');
        }

        return DateTimeValue::toDatabase($instant);
    }

    /** @param array<string, mixed> $config */
    private function validateUrl(mixed $value, array $config): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }
        $url = trim($value);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('invalid_answer');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowed = $config['allowedSchemes'] ?? ['http', 'https'];
        if (!is_array($allowed) || $allowed === []) {
            $allowed = ['http', 'https'];
        }
        $allowed = array_map(static fn(mixed $s): string => strtolower((string) $s), $allowed);
        if (!in_array($scheme, $allowed, true)) {
            throw new \RuntimeException('invalid_answer');
        }

        return $url;
    }

    private function validatePhone(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }
        $normalized = preg_replace('/[\s\-\.\(\)\/]/', '', trim($value)) ?? '';
        if (preg_match('/^\+?\d{7,15}$/', $normalized) !== 1) {
            throw new \RuntimeException('invalid_answer');
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $optionIds
     */
    private function validateSingleChoice(mixed $value, array $config, array $optionIds): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('invalid_answer');
        }
        $id = trim($value);
        if (in_array($id, $optionIds, true)) {
            return $id;
        }

        if ((bool) ($config['allowOther'] ?? false)) {
            return $id;
        }

        throw new \RuntimeException('invalid_answer');
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $optionIds
     */
    private function validateMultipleChoice(mixed $value, array $config, array $optionIds): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) {
            throw new \RuntimeException('invalid_answer');
        }

        $selected = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new \RuntimeException('invalid_answer');
            }
            if ($optionIds !== [] && !in_array($entry, $optionIds, true)) {
                throw new \RuntimeException('invalid_answer');
            }
            $selected[] = $entry;
        }
        $selected = array_values(array_unique($selected));

        if (isset($config['minSelected']) && count($selected) < (int) $config['minSelected']) {
            throw new \RuntimeException('invalid_answer');
        }
        if (isset($config['maxSelected']) && count($selected) > (int) $config['maxSelected']) {
            throw new \RuntimeException('invalid_answer');
        }

        return json_encode($selected, JSON_UNESCAPED_UNICODE);
    }

    private function validateBoolean(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            if ((int) $value === 1 || (int) $value === 0) {
                return (string) (int) $value;
            }
            throw new \RuntimeException('invalid_answer');
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'ja'], true)) {
                return '1';
            }
            if (in_array($normalized, ['0', 'false', 'no', 'nein'], true)) {
                return '0';
            }
        }

        throw new \RuntimeException('invalid_answer');
    }

    /** @param array<string, mixed> $config */
    private function validateRating(mixed $value, array $config): string
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('invalid_answer');
        }
        $number = (float) $value;
        if (isset($config['min']) && $number < (float) $config['min']) {
            throw new \RuntimeException('invalid_answer');
        }
        if (isset($config['max']) && $number > (float) $config['max']) {
            throw new \RuntimeException('invalid_answer');
        }
        if (isset($config['step']) && (float) $config['step'] > 0) {
            $step = (float) $config['step'];
            $base = isset($config['min']) ? (float) $config['min'] : 0.0;
            $delta = ($number - $base) / $step;
            if (abs($delta - round($delta)) > 1e-9) {
                throw new \RuntimeException('invalid_answer');
            }
        }

        return (string) $number;
    }
}
