<?php

declare(strict_types=1);

namespace Cyonima\UI;

use Cyonima\Adw\Raw\PreferencesPage as RawPreferencesPage;

final class PreferencesPage extends Widget
{
    public function __construct(string $title = '', string $description = '')
    {
        parent::__construct(RawPreferencesPage::new());

        if ($title !== '') {
            $this->raw()->setTitle($title);
        }
        if ($description !== '') {
            $this->raw()->setDescription($description);
        }
    }

    public function raw(): RawPreferencesPage
    {
        /** @var RawPreferencesPage $raw */
        $raw = $this->raw;
        return $raw;
    }

    public function add(PreferencesGroup ...$groups): static
    {
        foreach ($groups as $group) {
            $this->raw()->add($group->raw());
        }
        return $this;
    }

    public function title(string $title): static
    {
        $this->raw()->setTitle($title);
        return $this;
    }

    public function icon(string $iconName): static
    {
        $this->raw()->setIconName($iconName);
        return $this;
    }
}
