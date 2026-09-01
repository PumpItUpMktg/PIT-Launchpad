<?php

namespace App\JobCapture\Photos;

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
 * Writes GPS coordinates into a photo's EXIF (§ Job Capture). Used to stamp a job's PUBLIC (jittered) point
 * into its photos so the images carry the approximate service location — and, crucially, so a tech phone's real
 * capture GPS is OVERWRITTEN with the privacy-safe point rather than leaking the customer's exact address in the
 * image metadata. The GPS sub-IFD is rebuilt from scratch each time, dropping any device altitude/timestamp.
 *
 * JPEG only (the format EXIF lives in). A non-JPEG is transcoded to JPEG via GD first; if it can't be processed
 * at all, the original bytes are returned unchanged (never throws) and the caller records it as not geotagged.
 */
final class ExifGeotagger
{
    public function stamp(string $bytes, float $lat, float $lng): string
    {
        $jpeg = $this->asJpeg($bytes);
        if ($jpeg === null) {
            return $bytes;
        }

        try {
            $pel = new PelJpeg(new PelDataWindow($jpeg));

            $exif = $pel->getExif();
            if ($exif === null) {
                // No EXIF yet (the common case for our uploads) — build the container from scratch.
                $exif = new PelExif;
                $pel->setExif($exif);
                $tiff = new PelTiff;
                $exif->setTiff($tiff);
                $ifd0 = new PelIfd(PelIfd::IFD0);
                $tiff->setIfd($ifd0);
            } else {
                // Existing EXIF (e.g. a device photo) — keep it, just replace the GPS block below. A malformed
                // tree here surfaces as a thrown call and is caught, leaving the original bytes untouched.
                $ifd0 = $exif->getTiff()->getIfd();
            }

            // A fresh GPS IFD replaces any existing one — so the device's true coordinates don't survive.
            $gps = new PelIfd(PelIfd::GPS);
            $ifd0->addSubIfd($gps);

            $gps->addEntry(new PelEntryByte(PelTag::GPS_VERSION_ID, 2, 3, 0, 0));
            $gps->addEntry(new PelEntryAscii(PelTag::GPS_LATITUDE_REF, $lat < 0 ? 'S' : 'N'));
            $gps->addEntry($this->dms(PelTag::GPS_LATITUDE, abs($lat)));
            $gps->addEntry(new PelEntryAscii(PelTag::GPS_LONGITUDE_REF, $lng < 0 ? 'W' : 'E'));
            $gps->addEntry($this->dms(PelTag::GPS_LONGITUDE, abs($lng)));

            return $pel->getBytes();
        } catch (Throwable) {
            return $bytes;
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

    /** JPEG bytes as-is, or transcoded from another format via GD, or null if it can't be made into a JPEG. */
    private function asJpeg(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }
            ob_start();
            imagejpeg($image, null, 90);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            return $jpeg !== '' ? $jpeg : null;
        } catch (Throwable) {
            return null;
        }
    }
}
