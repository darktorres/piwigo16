<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Admin\Projection\TabSheetEntry;
use Piwigo\Admin\Projection\TabsheetPageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

final class Tabsheet
{
    /**
     * @var array<string, TabSheetEntry>
     */
    public array $sheets = [];

    /**
     * Only ever assigned null (constructor) or a real string (setId()).
     */
    public ?string $uniqid = null;

    public string $selected = '';

    /*
      $name is the tabsheet's name inside the template .tpl file
      $titlename in the template is affected by $titlename value
    */
    public function __construct(
        public string $name = 'TABSHEET',
        public string $titlename = 'TABSHEET_TITLE'
    ) {}

    public function setId(string $id): void
    {
        $this->uniqid = $id;
    }

    /*
       add a tab
    */
    public function add(string $name, string $caption, string $url, bool $selected = false): bool
    {
        if (! isset($this->sheets[$name])) {
            $this->sheets[$name] = new TabSheetEntry($caption, $url);
            if ($selected) {
                $this->selected = $name;
            }
            return true;
        }
        return false;
    }

    /*
       remove a tab
    */
    public function delete(string $name): bool
    {
        if (isset($this->sheets[$name])) {
            unset($this->sheets[$name]);

            if ($this->selected === $name) {
                $this->selected = '';
            }
            return true;
        }
        return false;
    }

    /*
       select a tab to be active
    */
    public function select(string $name, EventDispatcher $eventDispatcher): void
    {
        $event = $eventDispatcher->dispatch(new TabsheetBeforeSelect($this->sheets, $this->uniqid));
        // 'tabsheet_before_select' handlers are documented to filter/append to
        // the array<string, TabSheetEntry> $sheets they receive and return
        // the same shape, but TabsheetBeforeSelect::$sheets itself is only
        // loosely typed array<mixed> (a misbehaving third-party handler
        // could populate it with anything, including a legacy raw
        // array{caption,url} entry instead of a real TabSheetEntry) --
        // rebuild it defensively instead of trusting it, keeping only
        // entries that match this property's real declared shape (string
        // key, a TabSheetEntry or an equivalent caption/url array).
        $filtered_sheets = [];
        foreach ($event->sheets as $sheet_name => $sheet) {
            if (! is_string($sheet_name)) {
                continue;
            }
            if ($sheet instanceof TabSheetEntry) {
                $filtered_sheets[$sheet_name] = $sheet;
            } elseif (is_array($sheet) && isset($sheet['caption'], $sheet['url']) && is_string($sheet['caption']) && is_string($sheet['url'])) {
                $filtered_sheets[$sheet_name] = new TabSheetEntry($sheet['caption'], $sheet['url']);
            }
        }
        $this->sheets = $filtered_sheets;

        if (! array_key_exists($name, $this->sheets)) {
            $keys = array_keys($this->sheets);
            $name = $keys[0];
        }
        $this->selected = $name;
    }

    /*
      set $titlename value
    */
    public function setTitlename(string $titlename): string
    {
        $this->titlename = $titlename;
        return $this->titlename;
    }

    /*
      returns $titlename value
    */
    public function getTitlename(): string
    {
        return $this->titlename;
    }

    /**
     * returns properties of selected tab
     */
    public function getSelected(): ?TabSheetEntry
    {
        if ($this->selected !== '') {
            return $this->sheets[$this->selected];
        } else {
            return null;
        }
    }

    /*
     * Build TabSheet and assign this content to current page
     *
     * Fill $this->$name {default value = TABSHEET} with HTML code for tabsheet
     * Fill $this->titlename {default value = TABSHEET_TITLE} with formated caption of the selected tab
     */
    public function assign(CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $selected_tab = $this->getSelected();

        $template->assignContext(new TabsheetPageContext(
            sheets: array_map(static fn (TabSheetEntry $entry): array => $entry->toArray(), $this->sheets),
            selected: $this->selected,
            titlenameKey: isset($selected_tab) ? $this->titlename : null,
            titlenameValue: isset($selected_tab) ? '[' . $selected_tab->caption . ']' : null,
        ));

        $template->assignVarFromTemplate($this->name, 'tabsheet.latte');
        $template->clearAssign('tabsheet');
    }
}
