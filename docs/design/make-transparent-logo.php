<?php

/*
| Removes the white background of docs/images/logo.png, keeping smooth edges.
|
|   php docs/design/make-transparent-logo.php
|
| Every logo color has one channel at zero (green #009C3B, yellow #FFDF00,
| blue #002776), so each anti-aliased edge pixel is "logo color blended with
| white" and can be un-blended exactly: alpha comes from the smallest channel
| (white = 255 everywhere, pure logo color = 0 somewhere).
*/

$source = __DIR__ . '/../images/logo.png';
$target = __DIR__ . '/../images/logo-transparent.png';

$in = imagecreatefrompng($source);
$width = imagesx($in);
$height = imagesy($in);

$out = imagecreatetruecolor($width, $height);
imagealphablending($out, false);
imagesavealpha($out, true);

for ($x = 0; $x < $width; $x++) {
    for ($y = 0; $y < $height; $y++) {
        $c = imagecolorsforindex($in, imagecolorat($in, $x, $y));
        $alpha = 1 - min($c['red'], $c['green'], $c['blue']) / 255;

        if ($alpha <= 0.0) {
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));
            continue;
        }

        // C = alpha * F + (1 - alpha) * 255  =>  F = (C - (1 - alpha) * 255) / alpha
        $unblend = fn ($v) => (int) max(0, min(255, round(($v - (1 - $alpha) * 255) / $alpha)));

        imagesetpixel($out, $x, $y, imagecolorallocatealpha(
            $out,
            $unblend($c['red']),
            $unblend($c['green']),
            $unblend($c['blue']),
            (int) round((1 - $alpha) * 127)
        ));
    }
}

imagepng($out, $target);
echo "Wrote {$target}\n";
