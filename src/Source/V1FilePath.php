<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

/**
 * Where a v1 file row's bytes are, relative to upload/files.
 *
 * v1 has two layouts and both are common. With
 * `uploads_organize_folders_by_date` off, everything sits flat in
 * upload/files. With it on, files go under YYYY/MM.
 *
 * The trap is that `disk_folder_month` stores a plain integer while the
 * directory on disk is **zero-padded** — v1 re-pads it when reading
 * (`Files::get()`), and anything that does not will look for `2026/3/`
 * and conclude that every file uploaded in March is missing.
 *
 * `url` is the on-disk name: `{unix timestamp}-{16 hex}-{sanitised
 * original}.{ext}`. It is not the display name (that is `filename`) and
 * not the uploaded name (that is `original_url`).
 */
final class V1FilePath
{
    /**
     * @param  array<string, mixed>  $row  a tbl_files row
     * @param  bool  $dated  the install's uploads_organize_folders_by_date
     */
    public static function for(array $row, bool $dated): string
    {
        $name = (string) ($row['url'] ?? '');

        if (! $dated) {
            return $name;
        }

        $year = $row['disk_folder_year'] ?? null;
        $month = $row['disk_folder_month'] ?? null;

        // A dated install still holds rows from before the option was
        // turned on, and those sit flat.
        if ($year === null || $month === null) {
            return $name;
        }

        return sprintf('%04d/%02d/%s', (int) $year, (int) $month, $name);
    }
}
