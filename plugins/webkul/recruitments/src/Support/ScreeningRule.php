<?php

namespace Webkul\Recruitment\Support;

use Webkul\Recruitment\Enums\ScreeningQuestionType;

/**
 * Checks one answer against a question's must-have rule ("knockout"):
 *   yes_no        {"required_answer": "yes"|"no"}
 *   number        {"min": ?number, "max": ?number}
 *   single_choice {"accepted": [option, ...]}
 *   multi_choice  {"must_include": [option, ...]}
 *   text          no rule (read by a person)
 * Works on the question snapshot, so it judges the question as it was asked.
 */
final class ScreeningRule
{
    /**
     * @param  array{type: string, options?: array<int, string>, is_required?: bool, knockout?: array<string, mixed>|null}  $question
     * @return array{passed: ?bool, reason: ?string, answer: mixed} passed: true/false, or null when there is nothing to judge
     */
    public static function evaluate(array $question, mixed $answer): array
    {
        $type = ScreeningQuestionType::from($question['type']);
        $knockout = (array) ($question['knockout'] ?? []);
        $options = array_values((array) ($question['options'] ?? []));

        if (self::isBlank($answer)) {
            return ($question['is_required'] ?? false)
                ? ['passed' => false, 'reason' => 'Not answered', 'answer' => null]
                : ['passed' => null, 'reason' => null, 'answer' => null];
        }

        return match ($type) {
            ScreeningQuestionType::YES_NO        => self::yesNo($answer, $knockout),
            ScreeningQuestionType::NUMBER        => self::number($answer, $knockout),
            ScreeningQuestionType::SINGLE_CHOICE => self::singleChoice($answer, $options, $knockout),
            ScreeningQuestionType::MULTI_CHOICE  => self::multiChoice($answer, $options, $knockout),
            ScreeningQuestionType::TEXT          => ['passed' => null, 'reason' => null, 'answer' => trim((string) $answer)],
        };
    }

    /**
     * Keep only the rule fields that fit the question type (the form keeps
     * values of other types when HR switches type), and drop options the
     * question no longer offers. Null when there is no rule.
     *
     * @param  array<string, mixed>|null  $knockout
     * @param  array<int, string>|null  $options
     * @return array<string, mixed>|null
     */
    public static function normalizeKnockout(ScreeningQuestionType $type, ?array $knockout, ?array $options): ?array
    {
        $knockout = (array) $knockout;
        $options = array_values((array) $options);

        $rule = match ($type) {
            ScreeningQuestionType::YES_NO        => in_array($knockout['required_answer'] ?? null, ['yes', 'no'], true) ? ['required_answer' => $knockout['required_answer']] : [],
            ScreeningQuestionType::NUMBER        => array_filter([
                'min' => is_numeric($knockout['min'] ?? null) ? $knockout['min'] + 0 : null,
                'max' => is_numeric($knockout['max'] ?? null) ? $knockout['max'] + 0 : null,
            ], fn ($value): bool => $value !== null),
            ScreeningQuestionType::SINGLE_CHOICE => array_filter(['accepted' => array_values(array_intersect((array) ($knockout['accepted'] ?? []), $options))]),
            ScreeningQuestionType::MULTI_CHOICE  => array_filter(['must_include' => array_values(array_intersect((array) ($knockout['must_include'] ?? []), $options))]),
            ScreeningQuestionType::TEXT          => [],
        };

        return $rule === [] ? null : $rule;
    }

    /** @param array<string, mixed> $knockout */
    private static function yesNo(mixed $answer, array $knockout): array
    {
        $value = match (true) {
            is_bool($answer)                                                                => $answer ? 'yes' : 'no',
            in_array(strtolower(trim((string) $answer)), ['yes', 'y', '1', 'true'], true)   => 'yes',
            in_array(strtolower(trim((string) $answer)), ['no', 'n', '0', 'false'], true)   => 'no',
            default                                                                         => null,
        };

        if ($value === null) {
            return ['passed' => false, 'reason' => 'Answer must be Yes or No', 'answer' => $answer];
        }

        $required = $knockout['required_answer'] ?? null;
        if (! in_array($required, ['yes', 'no'], true)) {
            return ['passed' => null, 'reason' => null, 'answer' => $value];
        }

        return $value === $required
            ? ['passed' => true, 'reason' => null, 'answer' => $value]
            : ['passed' => false, 'reason' => 'Answered '.ucfirst($value).'; this job needs '.ucfirst($required), 'answer' => $value];
    }

    /** @param array<string, mixed> $knockout */
    private static function number(mixed $answer, array $knockout): array
    {
        if (! is_numeric($answer)) {
            return ['passed' => false, 'reason' => 'Answer must be a number', 'answer' => $answer];
        }

        $value = $answer + 0;
        $min = isset($knockout['min']) && is_numeric($knockout['min']) ? $knockout['min'] + 0 : null;
        $max = isset($knockout['max']) && is_numeric($knockout['max']) ? $knockout['max'] + 0 : null;

        if ($min === null && $max === null) {
            return ['passed' => null, 'reason' => null, 'answer' => $value];
        }

        if ($min !== null && $value < $min) {
            return ['passed' => false, 'reason' => "Answered {$value}; minimum is {$min}", 'answer' => $value];
        }

        if ($max !== null && $value > $max) {
            return ['passed' => false, 'reason' => "Answered {$value}; maximum is {$max}", 'answer' => $value];
        }

        return ['passed' => true, 'reason' => null, 'answer' => $value];
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<string, mixed>  $knockout
     */
    private static function singleChoice(mixed $answer, array $options, array $knockout): array
    {
        $value = is_array($answer) ? (string) reset($answer) : (string) $answer;

        if (! in_array($value, $options, true)) {
            return ['passed' => false, 'reason' => 'Not one of the offered options', 'answer' => $value];
        }

        $accepted = array_values(array_filter((array) ($knockout['accepted'] ?? []), 'strlen'));
        if ($accepted === []) {
            return ['passed' => null, 'reason' => null, 'answer' => $value];
        }

        return in_array($value, $accepted, true)
            ? ['passed' => true, 'reason' => null, 'answer' => $value]
            : ['passed' => false, 'reason' => "Answered \"{$value}\"; accepted: ".implode(', ', $accepted), 'answer' => $value];
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<string, mixed>  $knockout
     */
    private static function multiChoice(mixed $answer, array $options, array $knockout): array
    {
        $values = array_values(array_unique(array_map('strval', is_array($answer) ? $answer : [$answer])));

        if (array_diff($values, $options) !== []) {
            return ['passed' => false, 'reason' => 'Not one of the offered options', 'answer' => $values];
        }

        $mustInclude = array_values(array_filter((array) ($knockout['must_include'] ?? []), 'strlen'));
        if ($mustInclude === []) {
            return ['passed' => null, 'reason' => null, 'answer' => $values];
        }

        $missing = array_values(array_diff($mustInclude, $values));

        return $missing === []
            ? ['passed' => true, 'reason' => null, 'answer' => $values]
            : ['passed' => false, 'reason' => 'Missing: '.implode(', ', $missing), 'answer' => $values];
    }

    private static function isBlank(mixed $answer): bool
    {
        return $answer === null || $answer === '' || $answer === [] || (is_string($answer) && trim($answer) === '');
    }
}
