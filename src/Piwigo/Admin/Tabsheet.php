<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Template\Template;

final class Tabsheet
{
    /**
     * @var array<string, array{caption: string, url: string}>
     */
    public $sheets;

    /**
     * Only ever assigned null (constructor) or a real string (set_id()) --
     * confirmed via every real read/write site in this class.
     *
     * @var ?string
     */
    public $uniqid;

    /**
     * @var string
     */
    public $selected;

    /*
      $name is the tabsheet's name inside the template .tpl file
      $titlename in the template is affected by $titlename value
    */
    public function __construct(
        public string $name = 'TABSHEET',
        public string $titlename = 'TABSHEET_TITLE'
    ) {
        $this->sheets = [];
        $this->uniqid = null;
        $this->selected = '';
    }

    public function set_id(string $id): void
    {
        $this->uniqid = $id;
    }

    /*
       add a tab
    */
    public function add(string $name, string $caption, string $url, bool $selected = false): bool
    {
        if (! isset($this->sheets[$name])) {
            $this->sheets[$name] = [
                'caption' => $caption,
                'url' => $url,
            ];
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
    public function select(string $name): void
    {
        $event = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new TabsheetBeforeSelect($this->sheets, $this->uniqid));
        // 'tabsheet_before_select' handlers are documented to filter/append to
        // the array<string, array{caption: string, url: string}> $sheets they
        // receive and return the same shape, but TabsheetBeforeSelect::$sheets
        // itself is only loosely typed array<mixed> (a misbehaving third-party
        // handler could populate it with anything) -- rebuild it defensively
        // instead of trusting it, keeping only entries that match this
        // property's real declared shape (string key, caption/url strings).
        $filtered_sheets = [];
        foreach ($event->sheets as $sheet_name => $sheet) {
            if (is_string($sheet_name) && is_array($sheet) && isset($sheet['caption'], $sheet['url']) && is_string($sheet['caption']) && is_string($sheet['url'])) {
                $filtered_sheets[$sheet_name] = [
                    'caption' => $sheet['caption'],
                    'url' => $sheet['url'],
                ];
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
    public function set_titlename(string $titlename): string
    {
        $this->titlename = $titlename;
        return $this->titlename;
    }

    /*
      returns $titlename value
    */
    public function get_titlename(): string
    {
        return $this->titlename;
    }

    /**
     * returns properties of selected tab
     *
     * @return array{caption: string, url: string}|null
     */
    public function get_selected(): ?array
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
    public function assign(\Piwigo\Template\CurrentTemplate $currentTemplate): void
    {
        $template = $currentTemplate->get();

        $template->set_filename('tabsheet', 'tabsheet.tpl');
        $template->assign('tabsheet', $this->sheets);
        $template->assign('tabsheet_selected', $this->selected);

        $selected_tab = $this->get_selected();

        if (isset($selected_tab)) {
            $template->assign(
                [
                    $this->titlename => '[' . $selected_tab['caption'] . ']',
                ]
            );
        }

        $template->assign_var_from_handle($this->name, 'tabsheet');
        $template->clear_assign('tabsheet');
    }
}
