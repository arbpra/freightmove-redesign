<?php

namespace App\Support;

/**
 * How large an upload this server will actually accept.
 *
 * `freightmove.loads.max_image_kb` is what the product wants. PHP's
 * `upload_max_filesize` and `post_max_size` are what the machine allows, and
 * they win silently: a file over `upload_max_filesize` arrives with an error
 * code instead of contents, and a request over `post_max_size` arrives with
 * `$_POST` and `$_FILES` both empty — so Laravel reports "the file field is
 * required" for a file the shipper definitely chose.
 *
 * That mismatch is the default state of a fresh PHP install, not an exotic
 * misconfiguration: 2M upload_max_filesize against a 6MB product limit, while
 * a photo off any recent phone is 3-5MB. The symptom is a form that rejects
 * exactly the pictures people try hardest to send.
 *
 * So the effective limit is the smallest of the three, and it is what gets
 * validated against and quoted in the error. Raising the product limit alone
 * changes nothing until the ini settings move with it.
 */
class UploadLimit
{
    /**
     * Kilobytes this server will really take, honouring PHP's ceilings.
     *
     * `post_max_size` has to carry the file plus the rest of the multipart
     * body, so a little headroom is left rather than quoting it exactly.
     */
    public static function maxKb(int $wantedKb): int
    {
        $limits = [$wantedKb];

        if ($upload = self::iniKb('upload_max_filesize')) {
            $limits[] = $upload;
        }

        if ($post = self::iniKb('post_max_size')) {
            // Field data and multipart boundaries share this budget.
            $limits[] = max(1, $post - 64);
        }

        return max(1, min($limits));
    }

    /** A human number for an error message: "6MB", "1.5MB". */
    public static function label(int $kb): string
    {
        $mb = $kb / 1024;

        return $mb >= 10 || fmod($mb, 1.0) === 0.0
            ? sprintf('%dMB', (int) round($mb))
            : sprintf('%.1fMB', $mb);
    }

    /**
     * An ini size in kilobytes, or null when it is unlimited or unset.
     *
     * These are written in PHP's shorthand — "2M", "8M", "512K" — and a bare
     * integer means bytes. `-1` and `0` mean no limit.
     */
    private static function iniKb(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;

        if ($value <= 0) {
            return null;
        }

        $bytes = match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        return (int) floor($bytes / 1024);
    }
}
