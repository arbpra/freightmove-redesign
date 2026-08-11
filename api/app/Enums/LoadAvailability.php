<?php

namespace App\Enums;

/**
 * How soon the shipper needs the load moved.
 *
 * Distinct from JobStatus: a load can be `published` and still be months out.
 * Values decoded from the legacy `load_master.availability` int — see
 * docs/10-domain-rules.md section 2. All four are in live use.
 */
enum LoadAvailability: string
{
    case Asap = 'asap';
    case ReadyNow = 'ready_now';
    case AvailableFrom = 'available_from';
    case Planning = 'planning';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Maps the legacy integer code. Unknown codes become null, never a guess. */
    public static function fromLegacy(int|string|null $code): ?self
    {
        return match ((int) $code) {
            1 => self::Asap,
            2 => self::ReadyNow,
            3 => self::AvailableFrom,
            4 => self::Planning,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asap => 'ASAP',
            self::ReadyNow => 'Ready now',
            self::AvailableFrom => 'Available from a date',
            self::Planning => 'Planning and budgeting',
        };
    }

    /** Loads a carrier can act on now, used to rank the board. */
    public function isUrgent(): bool
    {
        return in_array($this, [self::Asap, self::ReadyNow], true);
    }
}
