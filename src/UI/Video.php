<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Gtk\Raw\Video as RawVideo;

/**
 * Lecteur vidéo GTK4.
 *
 * La lecture dépend des plugins GStreamer installés sur la machine. Le widget
 * reste utilisable sans plugin (surface vide) et se configure après coup.
 */
final class Video extends Widget
{
    public function __construct(string $filename = '')
    {
        parent::__construct($filename === '' ? RawVideo::new() : RawVideo::newForFilename($filename));
    }

    public function raw(): RawVideo
    {
        /** @var RawVideo $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function filename(string $filename): static
    {
        $this->raw()->setFilename($filename);
        return $this;
    }

    public function autoplay(bool $autoplay = true): static
    {
        $this->raw()->setAutoplay($autoplay);
        return $this;
    }

    public function loop(bool $loop = true): static
    {
        $this->raw()->setLoop($loop);
        return $this;
    }
}
