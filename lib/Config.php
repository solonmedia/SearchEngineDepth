<?php

namespace SearchEngine;

use ProcessWire\InputfieldWrapper,
    ProcessWire\Inputfield;

/**
 * SearchEngine Config
 *
 * @version 0.4.0
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
class Config extends Base {

    /**
     * Config data array passed from the SearchEngine module
     *
     * @var array
     */
    protected $data = [];

    /**
     * Constructor method
     *
     * @param array $data Config data array.
     */
    public function __construct(array $data) {
        parent::__construct();
        $this->data = $data;
    }

    /**
     * Validate (and optionally create new) index field
     */
    public function validateIndexField() {
        $index_field_name = $data['index_field'] ?? $this->getOptions()['index_field'] ?? null;
        if (!empty($index_field_name)) {
            $index_field_name = $this->wire('sanitizer')->fieldName($index_field_name);
            $create_link_base = $this->wire('config')->urls->admin . 'module/edit?name=SearchEngine&create_index_field=';
            $search_engine = $this->wire('modules')->get('SearchEngine');
            if ($this->wire('input')->get('create_index_field') == 1) {
                $index_field = $search_engine->createIndexField($index_field_name, $create_link_base . '2');
            }
            $index_field = $search_engine->getIndexField($index_field_name);
            if (!$index_field) {
                $create_link = ' <a href="' . $create_link_base . '1">'
                             . $this->_('Click here to create the index field automatically.')
                             . '</a>';
                $this->wire->message(sprintf(
                    $this->_('Index field "%s" (FieldtypeTextarea or FieldtypeTextareaLanguage) doesn\'t exist.'),
                    $index_field_name
                ) . $create_link, \ProcessWire\Notice::allowMarkup);
            } else if (!$index_field->_is_valid_index_field) {
                $this->wire->error(sprintf(
                    $this->_('Index field "%s" exists but is of incompatible type (%s). Please create a new index field or convert existing field to a supported type (FieldtypeTextarea or FieldtypeTextareaLanguage).'),
                    $index_field_name,
                    $index_field->type->name
                ));
            } else if (!$index_field->getTemplates()->count()) {
                $this->wire->message(sprintf(
                    $this->_('Index field "%s" hasn\'t been added to any templates yet. Add to one or more templates to start indexing content.'),
                    $index_field_name
                ));
            }
        }
    }

    /**
     * Get all config fields for the module
     *
     * @return InputfieldWrapper InputfieldWrapper with module config inputfields.
     */
    public function getFields(): InputfieldWrapper {

        $fields = $this->wire(new InputfieldWrapper());
        $modules = $this->wire('modules');
        $options = $this->getOptions();
        $data = $this->data;

        // fieldset for indexing options
        $indexing_options = $modules->get('InputfieldFieldset');
        $indexing_options->label = $this->_('Indexing options');
        $indexing_options->icon = 'database';
        $fields->add($indexing_options);

        // select indexed fields
        $indexed_fields = $modules->get('InputfieldAsmSelect');
        $indexed_fields->name = 'indexed_fields';
        $indexed_fields->label = $this->_('Select indexed fields');
        $compatible_fieldtype_options = $options['compatible_fieldtypes'] ?? [];
        if (!empty($data['override_compatible_fieldtypes'])) {
            $compatible_fieldtype_options = $data['compatible_fieldtypes'] ?? [];
        }
        if (!empty($compatible_fieldtype_options)) {
            foreach ($this->wire('fields')->getAll() as $field) {
                if (!in_array($field->type, $compatible_fieldtype_options) || $field->name === $options['index_field']) {
                    continue;
                }
                $indexed_fields->addOption($field->name);
            }
        }
        if (!empty($this->wire('config')->SearchEngine[$indexed_fields->name])) {
            $indexed_fields->notes = $this->_('Indexed fields are currently defined in site config. You cannot override config settings here.');
            $indexed_fields->value = $options[$indexed_fields->name];
            $indexed_fields->collapsed = Inputfield::collapsedNoLocked;
        } else {
            $indexed_fields->value = $data[$indexed_fields->name] ?? $options[$indexed_fields->name] ?? null;
        }
        $indexing_options->add($indexed_fields);

        // select indexed templates
        $indexed_templates = $modules->get('InputfieldCheckboxes');
        $indexed_templates->name = 'indexed_templates';
        $indexed_templates->label = $this->_('Indexed templates');
        $indexed_templates->description = $this->_('In order for a template to be indexed, it needs to include the index field. You can use this setting to add the index field to one or more templates, or remove it from templates it has previously been added to.');
        $index_field_templates = $this->wire('fields')->get($options['index_field'])->getTemplates()->get('name[]');
        foreach ($this->wire('templates')->getAll() as $template) {
            $option_attributes = null;
            if ($template->flags & \ProcessWire\Template::flagSystem) {
                if (!in_array($template->name, $index_field_templates)) continue;
                $option_attributes = ['disabled' => 'disabled'];
                $indexed_templates->notes = $this->_('One or more system templates are indexed. In order to make system templates indexable (or non-indexable) you need to modify template settings directly.');
            }
            $indexed_templates->addOption($template->name, null, $option_attributes);
        }
        $indexed_templates->optionColumns = 1;
        $indexed_templates->value = $index_field_templates;
        $indexing_options->add($indexed_templates);

        // fieldset for manual indexing options
        $manual_indexing = $modules->get('InputfieldFieldset');
        $manual_indexing->label = $this->_('Manual indexing');
        $manual_indexing->icon = 'rocket';
        $fields->add($manual_indexing);

        // checkbox field for triggering page indexing
        $index_pages_now = $modules->get('InputfieldCheckbox');
        $index_pages_now->name = 'index_pages_now';
        $index_pages_now->label = $this->_('Index pages now?');
        $index_pages_now->description = $this->_('If you check this field and save module settings, SearchEngine will automatically index all applicable pages.');
        $index_pages_now->notes = $this->_('Note: this operation may take a long time.');
        $index_pages_now->attr('checked', !empty($data[$index_pages_now->name]));
        $manual_indexing->add($index_pages_now);

        // optional selector for automatic page indexing
        $index_pages_now_selector = $modules->get('InputfieldSelector');
        $index_pages_now_selector->name = 'index_pages_now_selector';
        $index_pages_now_selector->label = $this->_('Selector for indexed pages');
        $index_pages_now_selector->description = $this->_('You can use this field to choose the pages that should be indexed. This only takes effect if the "Index pages now?" option has been checked.');
        $index_pages_now_selector->showIf = 'index_pages_now=1';
        $index_pages_now_selector->value = $data[$index_pages_now_selector->name] ?? null;
        $manual_indexing->add($index_pages_now_selector);

        // fieldset for advanced options
        $advanced_settings = $modules->get('InputfieldFieldset');
        $advanced_settings->label = $this->_('Advanced settings');
        $advanced_settings->icon = 'graduation-cap';
        $advanced_settings->collapsed = Inputfield::collapsedYes;
        $fields->add($advanced_settings);

        // select index field
        $index_field = $modules->get('InputfieldSelect');
        $index_field->name = 'index_field';
        $index_field->label = $this->_('Select index field');
        foreach ($this->wire('fields')->getAll() as $field) {
            if ($field->type != 'FieldtypeTextarea' && $field->type != 'FieldtypeTextareaLanguage') {
                continue;
            }
            $index_field->addOption($field->name);
        }
        if (!empty($this->wire('config')->SearchEngine[$index_field->name])) {
            $index_field->notes = $this->_('Index field is currently defined in site config. You cannot override config settings here.');
            $index_field->value = $options[$index_field->name];
            $index_field->collapsed = Inputfield::collapsedNoLocked;
        } else {
            $index_field->value = $data[$index_field->name] ?? $options[$index_field->name] ?? null;
            $index_field->notes = $this->_('If you select a field that already contains values, those values *will* be overwritten the next time someone triggers manual indexing of pages *or* a page containing selected field is saved. Making changes to this setting can result in *permanent* data loss!');
        }
        $advanced_settings->add($index_field);

        // override values for compatible fieldtypes
        $override_compatible_fieldtypes = $modules->get('InputfieldCheckbox');
        $override_compatible_fieldtypes->name = 'override_compatible_fieldtypes';
        $override_compatible_fieldtypes->label = $this->_('Override compatible fieldtypes');
        $override_compatible_fieldtypes->description = $this->_('Check this field if you want to override default compatible fieldtype values here.');
        $override_compatible_fieldtypes->attr('checked', !empty($data['override_compatible_fieldtypes']));
        $advanced_settings->add($override_compatible_fieldtypes);

        // define fieldtypes considered compatible with this module
        $compatible_fieldtypes = $modules->get('InputfieldAsmSelect');
        $compatible_fieldtypes->name = 'compatible_fieldtypes';
        $compatible_fieldtypes->label = $this->_('Compatible fieldtypes');
        $compatible_fieldtypes->description = $this->_('Fieldtypes considered compatible with this module.');
        $compatible_fieldtypes->showIf = 'override_compatible_fieldtypes=1';
        $incompatible_fieldtype_options = [
            'FieldtypePassword',
            'FieldtypeFieldsetOpen',
            'FieldtypeFieldsetClose',
            'FieldtypeFieldsetPage',
        ];
        foreach ($modules->find('className^=Fieldtype') as $fieldtype) {
            if (in_array($fieldtype->name, $incompatible_fieldtype_options)) {
                continue;
            }
            $compatible_fieldtypes->addOption($fieldtype->name);
        }
        if (!empty($this->wire('config')->SearchEngine[$compatible_fieldtypes->name])) {
            $compatible_fieldtypes->notes = $this->_('Compatible fieldtypes are currently defined in site config. You cannot override config settings here.');
            $compatible_fieldtypes->value = $options[$compatible_fieldtypes->name];
            $compatible_fieldtypes->collapsed = Inputfield::collapsedNoLocked;
        } else {
            $compatible_fieldtypes->value = $data[$compatible_fieldtypes->name] ?? $options[$compatible_fieldtypes->name] ?? null;
            $compatible_fieldtypes->notes = $this->_('Please note that selecting fieldtypes not selected by default may result in various problems. Change these values only if you\'re sure that you know what you\'re doing.');
        }
        $compatible_fieldtypes->notes .= $this->getCompatibleFieldtypeDiff($compatible_fieldtypes->value);
        $advanced_settings->add($compatible_fieldtypes);

        return $fields;
    }

    /**
     * Get a list of changes (additions and removals) made to the compatible fieldtypes setting
     *
     * @param array $compatible_fieldtypes Current list of compatible fieldtypes.
     * @return string String representation of the changes.
     */
    protected function getCompatibleFieldtypeDiff(array $compatible_fieldtypes): string {

        // get a diff by comparing module default setting value and current setting value
        $base = \ProcessWire\SearchEngine::$defaultOptions['compatible_fieldtypes'];
        $diff = array_filter([
            'added' => implode(', ', array_diff($compatible_fieldtypes, $base)),
            'removed' => implode(', ', array_filter(array_diff($base, $compatible_fieldtypes), function($fieldtype) {
                return $this->wire('modules')->isInstalled($fieldtype);
            })),
        ]);

        // construct output string
        $out = "";
        if (!empty($diff)) {
            $out .= "\n";
            if (!empty($diff['added'])) {
                $out .= "\n+ " . sprintf($this->_('Added fieldtypes: %s'), $diff['added']);
            }
            if (!empty($diff['removed'])) {
                $out .= "\n- " . sprintf($this->_('Removed fieldtypes: %s'), $diff['removed']);
            }
        }

        return $out;
    }
    
}
