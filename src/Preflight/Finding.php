<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

/**
 * One thing preflight noticed about a source or a host.
 *
 * Three levels, and the difference between them is what the operator is
 * allowed to do next:
 *
 *   BLOCKER — the run cannot start. Something would be lost or wrong and
 *             no flag overrides it (a missing host column, duplicate
 *             emails that would collapse two people into one account).
 *   ACKNOWLEDGE — the run can start once a human says they understand.
 *             Reserved for v1 features v2 has no equivalent for, where
 *             the honest outcome is "those rows are skipped and here they
 *             are" rather than a guess (encrypted files, S3 storage,
 *             per-file download limits).
 *   NOTE — informational, counted in the report, needs no decision.
 */
final class Finding
{
    public const BLOCKER = 'blocker';

    public const ACKNOWLEDGE = 'acknowledge';

    public const NOTE = 'note';

    /**
     * @param  array<string, mixed>  $context  structured detail for the
     *         report — counts, sample ids, the offending column name.
     *         Never free text the UI has to parse back out of $message.
     */
    public function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function blocker(string $code, string $message, array $context = []): self
    {
        return new self(self::BLOCKER, $code, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function acknowledge(string $code, string $message, array $context = []): self
    {
        return new self(self::ACKNOWLEDGE, $code, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function note(string $code, string $message, array $context = []): self
    {
        return new self(self::NOTE, $code, $message, $context);
    }

    /**
     * @return array{level: string, code: string, message: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
