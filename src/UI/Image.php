<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Image as RawImage;

/** Image affichée depuis un fichier local (asset du projet, SVG, PNG…). */
final class Image extends Widget
{
    public function __construct(string $file = '')
    {
        parent::__construct($file === '' ? RawImage::new() : RawImage::newFromFile($file));
    }

    public function raw(): RawImage
    {
        /** @var RawImage $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function fromFile(string $file): static
    {
        $this->raw()->setFromFile($file);
        return $this;
    }

    public function pixelSize(int $size): static
    {
        $this->raw()->setPixelSize($size);
        return $this;
    }
}
