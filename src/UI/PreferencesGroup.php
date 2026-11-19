<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\PreferencesGroup as RawPreferencesGroup;

final class PreferencesGroup extends Widget
{
    public function __construct(string $title = '', string $description = '')
    {
        parent::__construct(RawPreferencesGroup::new());

        if ($title !== '') {
            $this->raw()->setTitle($title);
        }
        if ($description !== '') {
            $this->raw()->setDescription($description);
        }
    }

    public function raw(): RawPreferencesGroup
    {
        /** @var RawPreferencesGroup $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function add(Widget ...$rows): static
    {
        foreach ($rows as $row) {
            $this->raw()->add($row->raw());
        }
        return $this;
    }

    public function title(string $title): static
    {
        $this->raw()->setTitle($title);
        return $this;
    }
}
