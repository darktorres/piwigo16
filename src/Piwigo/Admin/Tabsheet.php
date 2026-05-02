<?php

declare(strict_types=1);

namespace Piwigo\Admin;

class Tabsheet
{
    /** @var array<string, array<string, bool|string>> */
    public array $sheets = [];
    /**
     * @var null
     */
    public ?string $uniqid = null;
    public string $selected = '';

    /*
      $name is the tabsheet's name inside the template .tpl file
      $titlename in the template is affected by $titlename value
    */
    public function __construct(public string $name = 'TABSHEET', public string $titlename = 'TABSHEET_TITLE')
    {
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
        if (!isset($this->sheets[$name])) {
            $this->sheets[$name] = ['caption' => $caption,
                                         'url' => $url,
                                         'selected' => $selected];
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

            if ($this->selected == $name) {
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
        $this->sheets = trigger_change('tabsheet_before_select', $this->sheets, $this->uniqid);
        if (!array_key_exists((string) $name, $this->sheets)) {
            $keys = array_keys($this->sheets);
            $name = $keys[0];
        }
        $this->selected = $name;
    }

    /*
      set $titlename value
    */
    public function set_titlename(string $titlename): void
    {
        $this->titlename = $titlename;
    }

    /*
      returns $titlename value
    */
    public function get_titlename(): string
    {
        return $this->titlename;
    }

    /*
      returns properties of selected tab
    */
    /** @return array<string, bool|string>|null */
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
    public function assign(): void
    {
        global $template;

        $template->set_filename('tabsheet', 'tabsheet.tpl');
        $template->assign('tabsheet', $this->sheets);
        $template->assign('tabsheet_selected', $this->selected);

        $selected_tab = $this->get_selected();

        if (isset($selected_tab)) {
            $caption = is_scalar($selected_tab['caption'] ?? null) ? (string) $selected_tab['caption'] : '';
            $template->assign(
                [$this->titlename => '['.$caption.']']
            );
        }

        $template->assign_var_from_handle($this->name, 'tabsheet');
        $template->clear_assign('tabsheet');
    }
}
