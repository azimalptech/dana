<?php

declare(strict_types=1);

namespace Dana\Domain\Media;

/**
 * Turns Gemini's raw PCM into a file a phone can play (FR-15.18).
 *
 * The API returns headerless signed 16-bit little-endian mono PCM at
 * 24 kHz — not a playable file. Two things can be done with it:
 *
 *  - Wrap it in a 44-byte RIFF/WAVE header. Pure PHP, no dependency,
 *    works on any host. Costs ~48 KB per second.
 *  - Transcode to MP3, about a tenth of the size, which matters on
 *    mobile data in Turkmenistan — but needs ffmpeg on the machine.
 *
 * So: MP3 when the host can, WAV when it cannot. Both are already in
 * MediaController's accepted audio extensions and both stream through
 * the same GET /media/{name} route, so the choice changes the file size
 * and nothing else. Installing ffmpeg later needs no code change and no
 * migration — only the clips generated after it appear are smaller.
 */
final class Audio
{
    /** @return array{bytes: string, ext: string} */
    public static function encode(string $pcm, int $rate, int $bits, int $channels): array
    {
        $wav = self::wav($pcm, $rate, $bits, $channels);
        $mp3 = self::toMp3($wav);

        return $mp3 !== null
            ? ['bytes' => $mp3, 'ext' => 'mp3']
            : ['bytes' => $wav, 'ext' => 'wav'];
    }

    /** The canonical 44-byte RIFF/WAVE header in front of the samples. */
    public static function wav(string $pcm, int $rate, int $bits, int $channels): string
    {
        $blockAlign = (int) ($channels * $bits / 8);
        $byteRate = $rate * $blockAlign;

        return 'RIFF'
            . pack('V', 36 + strlen($pcm))
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)          // PCM header length
            . pack('v', 1)           // format 1 = uncompressed PCM
            . pack('v', $channels)
            . pack('V', $rate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bits)
            . 'data'
            . pack('V', strlen($pcm))
            . $pcm;
    }

    /** True when this host can produce MP3 rather than WAV. */
    public static function canEncodeMp3(): bool
    {
        return self::ffmpeg() !== null;
    }

    /** MP3 bytes, or null when no encoder is installed. */
    private static function toMp3(string $wav): ?string
    {
        $ffmpeg = self::ffmpeg();

        if ($ffmpeg === null) {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'dana_tts_');
        $out = $in . '.mp3';

        if ($in === false) {
            return null;
        }

        try {
            file_put_contents($in, $wav);

            // 64 kbps mono is generous for one spoken word and keeps a
            // ten-second dialogue under 100 KB.
            $command = sprintf(
                '%s -hide_banner -loglevel error -y -i %s -codec:a libmp3lame -b:a 64k -ac 1 %s',
                escapeshellarg($ffmpeg),
                escapeshellarg($in),
                escapeshellarg($out)
            );

            exec($command . ' 2>&1', $ignored, $status);

            if ($status !== 0 || !is_file($out) || filesize($out) === 0) {
                return null;
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** Cached because every clip in a bulk run would otherwise re-probe. */
    private static ?string $ffmpegPath = null;
    private static bool $probed = false;

    private static function ffmpeg(): ?string
    {
        if (self::$probed) {
            return self::$ffmpegPath;
        }

        self::$probed = true;

        $candidates = ['ffmpeg'];

        // Windows/XAMPP developer machines rarely have it on PATH.
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
        }

        foreach ($candidates as $candidate) {
            exec(escapeshellarg($candidate) . ' -version 2>&1', $ignored, $status);

            if ($status === 0) {
                return self::$ffmpegPath = $candidate;
            }
        }

        return self::$ffmpegPath = null;
    }
}
