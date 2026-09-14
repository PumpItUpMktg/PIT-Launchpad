<?php

namespace App\JobCapture\Photos;

use GdImage;
use lsolesen\pel\PelDataWindow;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelEntryByte;
use lsolesen\pel\PelEntryRational;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelTiff;
use Throwable;

/**
 * Scrubs a job photo's metadata and writes the job's PUBLIC (jittered) point as its only location (§ Job
 * Capture). Job photos arrive from anywhere — a tech's phone, a text message, a Google Photos download, a
 * screenshot — carrying whatever metadata that source left: EXIF GPS of wherever the photo was taken, XMP GPS
 * from an editing app, IPTC, device make/model, timestamps, embedded thumbnails. None of it may survive into the
 * stored bytes: the true address is never published, and a photo captured from the office must not say
 * "office" once the job is re-placed.
 *
 * {@see scrub()} therefore decodes the image, applies its EXIF orientation (so the pixels are upright without
 * needing the tag), re-encodes it — which drops EVERY metadata segment, not just the GPS block — and then
 * writes a fresh EXIF containing only a GPS sub-IFD with the given point. With no point it writes nothing: a
 * clean image. Undecodable bytes return null so the caller can refuse them; foreign metadata is never stored.
 */
final class ExifGeotagger
{
    private const JPEG_QUALITY = 90;

    /**
     * Clean bytes carrying only our GPS (or no GPS when $lat/$lng are null), or null when the image can't be
     * decoded — the caller must not store the original.
     */
    public function scrub(string $bytes, ?float $lat, ?float $lng): ?string
    {
        $clean = $this->reencode($bytes);
        if ($clean === null) {
            return null;
        }

        if ($lat === null || $lng === null) {
            return $clean;
        }

        return $this->withGps($clean, $lat, $lng) ?? $clean;
    }

    /**
     * Backwards-compatible entry: scrub + stamp, or the original bytes when the image can't be decoded. Prefer
     * {@see scrub()} anywhere the bytes are about to be STORED — this returns foreign metadata on failure.
     */
    public function stamp(string $bytes, float $lat, float $lng): string
    {
        return $this->scrub($bytes, $lat, $lng) ?? $bytes;
    }

    /** Decode any GD-readable format, apply the source's EXIF orientation, re-encode as a metadata-free JPEG. */
    private function reencode(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring($bytes);
            if (! $image instanceof GdImage) {
                return null;
            }
            $image = $this->orient($image, $this->orientationOf($bytes));

            ob_start();
            imagejpeg($image, null, self::JPEG_QUALITY);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            return $jpeg !== '' ? $jpeg : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The EXIF Orientation (1–8) of a JPEG's source bytes, 1 when absent or unreadable. */
    private function orientationOf(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        try {
            $pel = new PelJpeg(new PelDataWindow($bytes));
            $entry = $pel->getExif()?->getTiff()?->getIfd()?->getEntry(PelTag::ORIENTATION);
            $value = $entry !== null ? (int) $entry->getValue() : 1;

            return $value >= 1 && $value <= 8 ? $value : 1;
        } catch (Throwable) {
            return 1;
        }
    }

    /** Rotate / flip the pixels so the image is upright with orientation 1 (no tag needed afterwards). */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        $flip = static function (GdImage $img, int $mode): GdImage {
            imageflip($img, $mode);

            return $img;
        };
        $rotate = static fn (GdImage $img, float $angle): GdImage => imagerotate($img, $angle, 0) ?: $img;

        return match ($orientation) {
            2 => $flip($image, IMG_FLIP_HORIZONTAL),
            3 => $rotate($image, 180),
            4 => $flip($image, IMG_FLIP_VERTICAL),
            5 => $rotate($flip($image, IMG_FLIP_VERTICAL), -90),
            6 => $rotate($image, -90),
            7 => $rotate($flip($image, IMG_FLIP_HORIZONTAL), -90),
            8 => $rotate($image, 90),
            default => $image,
        };
    }

    /** A fresh EXIF container holding ONLY a GPS sub-IFD with the point; null if PEL can't write it. */
    private function withGps(string $jpeg, float $lat, float $lng): ?string
    {
        try {
            $pel = new PelJpeg(new PelDataWindow($jpeg));

            $exif = new PelExif;
            $pel->setExif($exif);
            $tiff = new PelTiff;
            $exif->setTiff($tiff);
            $ifd0 = new PelIfd(PelIfd::IFD0);
            $tiff->setIfd($ifd0);

            $gps = new PelIfd(PelIfd::GPS);
            $ifd0->addSubIfd($gps);

            $gps->addEntry(new PelEntryByte(PelTag::GPS_VERSION_ID, 2, 3, 0, 0));
            $gps->addEntry(new PelEntryAscii(PelTag::GPS_LATITUDE_REF, $lat < 0 ? 'S' : 'N'));
            $gps->addEntry($this->dms(PelTag::GPS_LATITUDE, abs($lat)));
            $gps->addEntry(new PelEntryAscii(PelTag::GPS_LONGITUDE_REF, $lng < 0 ? 'W' : 'E'));
            $gps->addEntry($this->dms(PelTag::GPS_LONGITUDE, abs($lng)));

            return $pel->getBytes();
        } catch (Throwable) {
            return null;
        }
    }

    /** Degrees/minutes/seconds as three rationals (seconds carried at 1/1000 precision). */
    private function dms(int $tag, float $coord): PelEntryRational
    {
        $degrees = (int) floor($coord);
        $minutesFloat = ($coord - $degrees) * 60;
        $minutes = (int) floor($minutesFloat);
        $seconds = (int) round(($minutesFloat - $minutes) * 60 * 1000);

        return new PelEntryRational($tag, [$degrees, 1], [$minutes, 1], [$seconds, 1000]);
    }
}
