<?php

declare(strict_types=1);

namespace Garner\Render;

final class Favicon
{
    public function __construct(
        private readonly string $sitePath,
    ) {}

    public function content(): string
    {
        $customPath = $this->sitePath . '/favicon.ico';

        if (is_file($customPath)) {
            return (string) file_get_contents($customPath);
        }

        return $this->fallbackIcon();
    }

    public function contentType(): string
    {
        return 'image/x-icon';
    }

    private function fallbackIcon(): string
    {
        $width = 16;
        $height = 16;
        $pixel = pack('C4', 255, 0, 0, 255);
        $pixelData = str_repeat($pixel, $width * $height);
        $maskData = str_repeat("\x00", 64);
        $bitmap = pack(
            'V3v2V6',
            40,
            $width,
            $height * 2,
            1,
            32,
            0,
            strlen($pixelData . $maskData),
            0,
            0,
            0,
            0,
        );
        $imageData = $bitmap . $pixelData . $maskData;

        return (
            pack('vvv', 0, 1, 1)
            . pack('CCCCvvVV', $width, $height, 0, 0, 1, 32, strlen($imageData), 22)
            . $imageData
        );
    }
}
