<?php
	
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.sectionmanager.php');
	
	require_once(EXTENSIONS . '/xmlimporter/lib/class.xmlimporter.php');
	require_once(EXTENSIONS . '/xmlimporter/lib/class.xmlimportermanager.php');
	
	class contentExtensionXmlImporterImporters extends AdministrationPage {
		protected $_handle = '';
		protected $_action = '';
		protected $_driver = null;
		protected $_editing = false;
		protected $_errors = array();
		protected $_fields = array();
		protected $_status = '';
		protected $_runs = array();
		protected $_importers = array();
		protected $_uri = null;
		protected $_valid = true;
		protected $_pagination = null;
		protected $_table_column = 'name';
		protected $_table_columns = array();
		protected $_table_direction = 'asc';
		
		public function __construct(&$parent){
			parent::__construct($parent);
			
			$this->_uri = URL . '/symphony/extension/xmlimporter';
			$this->_driver = $this->_Parent->ExtensionManager->create('xmlimporter');
		}
		
		public function build($context) {
			if (@$context[0] == 'edit' or @$context[0] == 'new') {
				$this->__prepareEdit($context);
			}
			
			else if (@$context[0] == 'run') {
				$this->__prepareRun($context);
			}
			
			else {
				$this->__prepareIndex();
			}
			
			parent::build($context);
		}
		
	/*-------------------------------------------------------------------------
		Run
	-------------------------------------------------------------------------*/
		
		public function __prepareRun($context) {
			$importManager = new XmlImporterManager($this->_Parent);
			
			array_shift($context);
			
			foreach ($context as $handle) {
				$importer = $importManager->create($handle);
				$status = $importer->validate();
				
				if ($status == XMLImporter::__OK__) {
					$importer->commit();
				}
				
				$this->_runs[] = array(
					'importer'	=> $importer,
					'status'	=> $status
				);
			}
		}
		
		public function __viewRun() {
			$this->setPageType('form');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('XML Importers') . ' &ndash; ' . __('Run'));
			$this->appendSubheading("<a href=\"{$this->_uri}/importers/\">" . __('XML Importers') . "</a> &raquo; " . __('Run'));
			
			foreach ($this->_runs as $run) {
				$importer = $run['importer'];
				$status = $run['status'];
				$entries = $importer->getEntries();
				$about = $importer->about();
				
				$fieldset = new XMLElement('fieldset');
				$fieldset->setAttribute('class', 'settings');
				$fieldset->appendChild(new XMLElement('legend', $about['name']));
				
				// Markup invalid:
				if ($status == XMLImporter::__ERROR_PREPARING__) {
					$fieldset->appendChild(new XMLElement(
						'h3', 'Import Failed'
					));
					
					$list = new XMLElement('ol');
					
					foreach ($importer->getErrors() as $error) {
						$list->appendChild(new XMLElement('li', $error));
					}
					
					$fieldset->appendChild($list);
				}
				
				// Invalid entry:
				else if ($status == XMLImporter::__ERROR_VALIDATING__) {
					$fieldset->appendChild(new XMLElement(
						'h3', 'Import Failed'
					));
					
					// Gather statistics:
					$failed = array();
					
					foreach ($entries as $index => $current) if (!is_null($current['errors'])) {
						$current['position'] = $index + 1;
						$failed[] = $current;
					}
					
					$fieldset->appendChild(new XMLElement(
						'p', sprintf(
							'Import failed because %d entries did not validate, a total of %d entries passed.',
							count($failed), count($entries) - count($failed)
						)
					));
					
					foreach ($failed as $index => $current) {
						$fieldset->appendChild(new XMLElement(
							'h3', sprintf('Import entry #%d', $current['position'])
						));
						
					// Errors -------------------------------------------------
						
						$list = new XMLElement('ol');
						
						foreach ($current['errors'] as $error) {
							$list->appendChild(new XMLElement('li', $error));
						}
						
						$fieldset->appendChild($list);
						
					// Source -------------------------------------------------
						
						$entry = $current['element'];
						$xml = new DOMDocument();
						$xml->preserveWhiteSpace = false;
						$xml->formatOutput = true;
						
						$xml->loadXML($entry->ownerDocument->saveXML($entry));
						
						$source = htmlentities($xml->saveXML($xml->documentElement), ENT_COMPAT, 'UTF-8');
						
						$fieldset->appendChild(new XMLElement(
							'pre', "<code>{$source}</code>"
						));
						
						foreach ($current['values'] as $field => $value) {
							$values[$field] = htmlentities($value);
						}
						
						ob_start();
						var_dump($values);
						$values_array = ob_get_contents();
						ob_end_clean();
						
						$fieldset->appendChild(new XMLElement(
							'pre',
							"<code>" . $values_array . "</code>"
						));
					}
				}
				
				// Passed:
				else {
					$fieldset->appendChild(new XMLElement(
						'h3', 'Import Complete'
					));
					
					$importer_result = array(
						'created' => 0,
						'updated' => 0,
						'skipped' => 0
					);
					
					foreach ($entries as $entry) {
						$importer_result[$entry['entry']->get('importer_status')]++;
					}
					
					$fieldset->appendChild(new XMLElement(
						'p', sprintf(
							'Import completed successfully: %d new entries were created, %d updated, and %d skipped.',
							$importer_result['created'],
							$importer_result['updated'],
							$importer_result['skipped']
						)
					));
					
				}
				
				$this->Form->appendChild($fieldset);
			}
		}
		
	/*-------------------------------------------------------------------------
		Edit
	-------------------------------------------------------------------------*/
		
		public function __prepareEdit($context) {
			if ($this->_editing = $context[0] == 'edit') {
				$this->_fields = $this->_driver->getXMLImporter($context[1]);
			}
			
			$this->_handle = $context[1];
			$this->_status = $context[2];
		}
		
		public function __actionNew() {
			$this->__actionEdit();
		}
		
		public function __actionEdit() {
			if (@array_key_exists('delete', $_POST['action'])) {
				$this->__actionEditDelete();
			}
			
			else {
				$this->__actionEditNormal();
			}
		}
		
		public function __actionEditDelete() {
			General::deleteFile($this->_fields['about']['file']);
			
			redirect("{$this->_uri}/importers/");
		}
		
		public function __actionEditNormal() {
			//header('content-type: text/plain');
			
		// Validate -----------------------------------------------------------
			
			$fields = $_POST['fields'];
			
			// Name:
			if (!isset($fields['about']['name']) or empty($fields['about']['name'])) {
				$this->_errors['name'] = 'Name must not be empty.';
			}
			
			// Source:
			if (!isset($fields['source']) or empty($fields['source'])) {
				$this->_errors['source'] = 'Source must not be empty.';
			}
			
			else if (!filter_var($fields['source'], FILTER_VALIDATE_URL)) {
				$this->_errors['source'] = 'Source is not a valid URL.';
			}
			
			// TODO: Validate included-elements
			
			$fields['about']['file'] = @$this->_fields['about']['file'];
			$fields['about']['created'] = @$this->_fields['about']['created'];
			$fields['about']['updated'] = @$this->_fields['about']['updated'];
			$fields['can-update'] = (@$fields['can-update'] == 'yes' ? 'yes' : 'no');
			$fields['unique-field'] = (integer)@$fields['unique-field'];
			$fields['section'] = (integer)@$fields['section'];
			$this->_fields = $fields;
			
			if (!empty($this->_errors)) {
				$this->_valid = false;
				return;
			}
			
		// Save ---------------------------------------------------------------
			
			$name = $this->_handle;
			
			if (!$this->_driver->setXMLImporter($name, $error, $this->_fields)) {
				$this->_valid = false;
				$this->_errors['other'] = $error;
				return;
			}
			
			if ($this->_editing) {
				redirect("{$this->_uri}/importers/edit/{$name}/saved/");
			}
			
			else {
				redirect("{$this->_uri}/importers/edit/{$name}/created/");
			}
		}
		
		public function __viewNew() {
			$this->__viewEdit();
		}
		
		public function __viewEdit() {
			$this->addStylesheetToHead(URL . '/extensions/xmlimporter/assets/xmlimporter.css', 'screen', 100);
			$this->addScriptToHead(URL . '/extensions/xmlimporter/assets/xmlimporter.js', 101);
			
		// Status: ------------------------------------------------------------
			
			if (!$this->_valid) {
				$message = __('An error occurred while processing this form <a href="#error">See below for details.</a>');
				
				if ($this->_errors['other']) {
					$message = $this->_errors['other'];
				}
				
				$this->pageAlert($message, Alert::ERROR);
			}
			
			// Status message:
			if ($this->_status) {
				$action = null;
				
				switch ($this->_status) {
					case 'saved': $action = '%1$s updated at %2$s. <a href="%3$s">Create another?</a> <a href="%4$s">View all %5$s</a>'; break;
					case 'created': $action = '%1$s created at %2$s. <a href="%3$s">Create another?</a> <a href="%4$s">View all %5$s</a>'; break;
				}
				
				if ($action) $this->pageAlert(
					__(
						$action, array(
							__('XML Importer'), 
							DateTimeObj::get(__SYM_TIME_FORMAT__), 
							URL . '/symphony/extension/xmlimporter/importers/new/', 
							URL . '/symphony/extension/xmlimporter/importers/',
							__('XML Importers')
						)
					),
					Alert::SUCCESS
				);
			}
			
		// Header: ------------------------------------------------------------
			
			$this->setPageType('form');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('XML Importers') . ' &ndash; ' . (
				@$this->_fields['about']['name'] ? $this->_fields['about']['name'] : __('Untitled')
			));
			$this->appendSubheading("<a href=\"{$this->_uri}/importers/\">" . __('XML Importers') . "</a> &raquo; " . (
				@$this->_fields['about']['name'] ? $this->_fields['about']['name'] : __('Untitled')
			));
			
		// About --------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Essentials')));
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Name'));
			$label->appendChild(Widget::Input(
				'fields[about][name]',
				General::sanitize(@$this->_fields['about']['name'])
			));
			
			if (isset($this->_errors['name'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['name']);
			}
			
			$group->appendChild($label);
			
			$label = Widget::Label(__('Description <i>Optional</i>'));
			$label->appendChild(Widget::Input(
				'fields[about][description]',
				General::sanitize(@$this->_fields['about']['description'])
			));
			
			$group->appendChild($label);
			$fieldset->appendChild($group);
			$this->Form->appendChild($fieldset);
			
		// Source -----------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Source')));
			
			$label = Widget::Label(__('URL'));		
			$label->appendChild(Widget::Input(
				'fields[source]', General::sanitize(@$this->_fields['source'])
			));
			
			if (isset($this->_errors['source'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['source']);
			}
			
			$fieldset->appendChild($label);
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__('Enter the URL of the XML document you want to process.'));
			$fieldset->appendChild($help);
			
			$label = Widget::Label(__('Included Elements'));		
			$label->appendChild(Widget::Input(
				'fields[included-elements]', General::sanitize(@$this->_fields['included-elements'])
			));
			
			if (isset($this->_errors['included-elements'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['included-elements']);
			}
			
			$fieldset->appendChild($label);
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__('Use an XPath expression to select which elements from the source XML to include.'));
			$fieldset->appendChild($help);
			
			$this->Form->appendChild($fieldset);
			
		// Section ------------------------------------------------------------
			
			$sectionManager = new SectionManager($this->_Parent);
			$sections = $sectionManager->fetch(NULL, 'ASC', 'name');
			$options = array();
			
			if (is_array($sections)) {
				$fieldset = new XMLElement('fieldset');
				$fieldset->setAttribute('class', 'settings');
				$fieldset->appendChild(new XMLElement('legend', __('Section')));
				
				if (is_array($sections)) foreach ($sections as $section) {
					$options[] = array($section->get('id'), (@$this->_fields['section'] == $section->get('id')), $section->get('name'));
				}
				
				$label = Widget::Label(__('Section'));		
				$label->appendChild(Widget::Select(
					'fields[section]', $options
				));
				
				$fieldset->appendChild($label);
				
				$label = new XMLElement('h3', __('Fields'));
				$label->setAttribute('class', 'label');
				$fieldset->appendChild($label);
				
				foreach ($sections as $section) {
					$section_fields = new XMLElement('ol');
					$section_fields->setAttribute('class', 'section-fields');
					$section_fields->setAttribute('id', 'section-' . $section->get('id'));
					
					foreach ($section->fetchFields() as $index => $field) {
						$field_id = $field->get('id');
						$field_name = "fields[fields][{$index}]";
						$field_data = null;
						
						if (isset($this->_fields['fields'])) {
							foreach ($this->_fields['fields'] as $temp_data) {
								if ($temp_data['field'] != $field_id) continue;
								
								$field_data = $temp_data;
							}
						}
						
						if (is_null($field_data)) continue;
						
						$li = new XMLElement('li');
						$li->appendChild(new XMLElement('h4', $field->get('label')));
						
						$input = Widget::Input("{$field_name}[field]", $field_id, 'hidden');
						$li->appendChild($input);
						
						$group = new XMLElement('div');
						$group->setAttribute('class', 'group');
						
						$label = Widget::Label('XPath Expression');
						$input = Widget::Input(
							"{$field_name}[xpath]",
							General::sanitize(@$field_data['xpath'])
						);
						$label->appendChild($input);
						$group->appendChild($label);
						
						$label = Widget::Label('PHP Function <i>Optional</i>');
						$input = Widget::Input(
							"{$field_name}[php]",
							General::sanitize(@$field_data['php'])
						);
						$label->appendChild($input);
						$group->appendChild($label);
						
						$li->appendChild($group);
						
						$label = Widget::Label();
						$label->setAttribute('class', 'meta');
						$input = Widget::Input("fields[unique-field]", $field_id, 'radio');
						
						if (@$this->_fields['unique-field'] == $field_id) {
							$input->setAttribute('checked', 'checked');
						}
						
						$label->setValue($input->generate(false) . ' Is unique');
						$li->appendChild($label);
						$section_fields->appendChild($li);
					}
					
					foreach ($section->fetchFields() as $index => $field) {
						$field_id = $field->get('id');
						$field_name = "mapping[-1]";
						
						$li = new XMLElement('li');
						$li->appendChild(new XMLElement('h4', $field->get('label')));
						$li->setAttribute('class', 'template');
						
						$input = Widget::Input("{$field_name}[field]", $field_id, 'hidden');
						$li->appendChild($input);
						
						$group = new XMLElement('div');
						$group->setAttribute('class', 'group');
						
						$label = Widget::Label('XPath Expression');
						$input = Widget::Input("{$field_name}[xpath]");
						$label->appendChild($input);
						$group->appendChild($label);
						
						$label = Widget::Label('PHP Function <i>Optional</i>');
						$input = Widget::Input("{$field_name}[php]");
						$label->appendChild($input);
						$group->appendChild($label);
						
						$li->appendChild($group);
						
						$label = Widget::Label();
						$label->setAttribute('class', 'meta');
						$input = Widget::Input("fields[unique-field]", $field_id, 'radio');
						
						$label->setValue($input->generate(false) . ' Is unique');
						$li->appendChild($label);
						$section_fields->appendChild($li);
					}
					
					$fieldset->appendChild($section_fields);
				}
				
				$label = Widget::Label();
				$label->setAttribute('class', 'meta');
				$input = Widget::Input("fields[unique-field]", $field_id, 'radio');
				
				if ((integer)@$this->_fields['unique-field'] == 0) {
					$input->setAttribute('checked', 'checked');
				}
				
				$label->setValue(__(
					'%s No field is unique', array(
						$input->generate(false)
					)
				));
				$fieldset->appendChild($label);
				
				$help = new XMLElement('p');
				$help->setAttribute('class', 'help');
				$help->setValue(__('If a field is flagged as unique, its value will be used to prevent duplicate entries from being created.'));
				$fieldset->appendChild($help);
				
				$label = Widget::Label();
				$input = Widget::Input('fields[can-update]', 'yes', 'checkbox');
				
				if (@$this->_fields['can-update'] == 'yes') {
					$input->setAttribute('checked', 'checked');
				}
				
				$label->setValue($input->generate(false) . ' Can update existing entries');
				$fieldset->appendChild($label);
				
				$help = new XMLElement('p');
				$help->setAttribute('class', 'help');
				$help->setValue(__('Allow entries to be updated from the source, only works when a unique field is chosen.'));
				$fieldset->appendChild($help);
				
				$this->Form->appendChild($fieldset);
			}
			
		// Footer -------------------------------------------------------------
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(
				Widget::Input('action[save]',
					($this->_editing ? __('Save Changes') : __('Create XML Importer')),
					'submit', array(
						'accesskey'		=> 's'
					)
				)
			);
			
			if ($this->_editing) {
				$button = new XMLElement('button', __('Delete'));
				$button->setAttributeArray(array(
					'name'		=> 'action[delete]',
					'class'		=> 'confirm delete',
					'title'		=> __('Delete this XML Importer')
				));
				$div->appendChild($button);
			}
			
			$this->Form->appendChild($div);
		}
		
	/*-------------------------------------------------------------------------
		Index
	-------------------------------------------------------------------------*/
		
		public function generateLink($values) {
			$values = array_merge(array(
				'pg'	=> $this->_pagination->page,
				'sort'	=> $this->_table_column,
				'order'	=> $this->_table_direction
			), $values);
			
			$count = 0;
			$link = $this->_Parent->getCurrentPageURL();
			
			foreach ($values as $key => $value) {
				if ($count++ == 0) {
					$link .= '?';
				}
				
				else {
					$link .= '&amp;';
				}
				
				$link .= "{$key}={$value}";
			}
			
			return $link;
		}
		
		public function __prepareIndex() {
			$this->_table_columns = array(
				'name'			=> array(__('Name'), true),
				'url'			=> array(__('URL'), true),
				'elements'		=> array(__('Included Elements'), true),
				'description'	=> array(__('Description'), false),
				'modified'		=> array(__('Modified'), true),
				'author'		=> array(__('Author'), true)
			);
			
			if (@$_GET['sort'] and $this->_table_columns[$_GET['sort']][1]) {
				$this->_table_column = $_GET['sort'];
			}
			
			if (@$_GET['order'] == 'desc') {
				$this->_table_direction = 'desc';
			}
			
			$this->_pagination = (object)array(
				'page'		=> (@(integer)$_GET['pg'] > 1 ? (integer)$_GET['pg'] : 1),
				'length'	=> $this->_Parent->Configuration->get('pagination_maximum_rows', 'symphony')
			);
			
			$this->_importers = $this->_driver->getXMLImporters(
				$this->_table_column,
				$this->_table_direction,
				$this->_pagination->page,
				$this->_pagination->length
			);
			
			// Calculate pagination:
			$this->_pagination->start = max(1, (($page - 1) * 17));
			$this->_pagination->end = (
				$this->_pagination->start == 1
				? $this->_pagination->length
				: $start + count($this->_importers)
			);
			$this->_pagination->total = $this->_driver->countXMLImporters();
			$this->_pagination->pages = ceil(
				$this->_pagination->total / $this->_pagination->length
			);
		}
		
		public function __actionIndex() {
			$checked = @array_keys($_POST['items']);
			
			if (is_array($checked) and !empty($checked)) {
				switch ($_POST['with-selected']) {
					case 'delete':
						foreach ($checked as $name) {
							$data = $this->_driver->getXMLImporter($name);
							
							General::deleteFile($data['about']['file']);
						}
						
						redirect("{$this->_uri}/importers/");
						break;
						
					case 'run':
						$url = '';
						
						foreach ($checked as $name) {
							$url .= "/{$name}";
						}
						
						redirect("{$this->_uri}/importers/run{$url}/");
						break;
				}
			}
		}
		
		public function __viewIndex() {
			$this->setPageType('table');
			$this->setTitle(__('Symphony') . ' &ndash; ' . __('XML Importers'));
			
			$this->appendSubheading(__('XML Importers'), Widget::Anchor(
				__('Create New'), "{$this->_uri}/importers/new/",
				__('Create a new XML Importer'), 'create button'
			));
			
			$tableHead = array();
			$tableBody = array();
			
			// Columns, with sorting:
			foreach ($this->_table_columns as $column => $values) {
				if ($values[1]) {
					if ($column == $this->_table_column) {
						if ($this->_table_direction == 'desc') {
							$direction = 'asc';
							$label = 'ascending';
						}
						
						else {
							$direction = 'desc';
							$label = 'descending';
						}
					}
					
					else {
						$direction = 'asc';
						$label = 'ascending';
					}
					
					$link = $this->generateLink(array(
						'sort'	=> $column,
						'order'	=> $direction
					));
					
					$anchor = Widget::Anchor($values[0], $link, __("Sort by {$label} " . strtolower($values[0])));
					
					if ($column == $this->_table_column) {
						$anchor->setAttribute('class', 'active');
					}
					
					$tableHead[] = array($anchor, 'col');
				}
				
				else {
					$tableHead[] = array($values[0], 'col');
				}
			}
			
			if (!is_array($this->_importers) or empty($this->_importers)) {
				$tableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', null, count($tableHead))))
				);
			}
			
			else {
				foreach ($this->_importers as $importer) {
					$col_name = Widget::TableData(
						Widget::Anchor(
							$importer['about']['name'],
							"{$this->_uri}/importers/edit/{$importer['about']['handle']}/"
						)
					);
					$col_name->appendChild(Widget::Input("items[{$importer['about']['handle']}]", null, 'checkbox'));
					
					$col_date = Widget::TableData(
						DateTimeObj::get(__SYM_DATETIME_FORMAT__, strtotime($importer['updated']))
					);
					
					if (!empty($importer['source'])) {
						$col_url = Widget::TableData(
							General::sanitize($importer['source'])
						);
					}
					
					else {
						$col_url = Widget::TableData('None', 'inactive');
					}
					
					if (!empty($importer['included-elements'])) {
						$col_elements = Widget::TableData(
							General::sanitize($importer['included-elements'])
						);
					}
					
					else {
						$col_elements = Widget::TableData('None', 'inactive');
					}
					
					if (!empty($importer['about']['description'])) {
						$col_description = Widget::TableData(
							General::sanitize($importer['about']['description'])
						);
					}
					
					else {
						$col_description = Widget::TableData('None', 'inactive');
					}
					
					if (isset($importer['about']['author']['website'])) {
						$col_author = Widget::TableData(Widget::Anchor(
							$importer['about']['author']['name'],
							General::validateURL($importer['about']['author']['website'])
						));
					}
					
					else if (isset($importer['about']['email'])) {
						$col_author = Widget::TableData(Widget::Anchor(
							$importer['about']['author']['name'],
							'mailto:' . $importer['about']['author']['email']
						));	
					}
					
					else if (isset($importer['about']['author']['name'])) {
						$col_author = Widget::TableData($importer['about']['author']['name']);
					}
					
					else {
						$col_author = Widget::TableData('None', 'inactive');
					}
					
					$tableBody[] = Widget::TableRow(array($col_name, $col_url, $col_elements, $col_description, $col_date, $col_author), null);
				}
			}
			
			$table = Widget::Table(
				Widget::TableHead($tableHead), null, 
				Widget::TableBody($tableBody)
			);
			
			$this->Form->appendChild($table);
			
			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			
			$options = array(
				array(null, false, 'With Selected...'),
				array('delete', false, 'Delete'),
				array('run', false, 'Run')
			);

			$actions->appendChild(Widget::Select('with-selected', $options));
			$actions->appendChild(Widget::Input('action[apply]', 'Apply', 'submit'));
			
			$this->Form->appendChild($actions);
			
			// Pagination:
			if ($this->_pagination->pages > 1) {
				$ul = new XMLElement('ul');
				$ul->setAttribute('class', 'page');
				
				// First:
				$li = new XMLElement('li');
				$li->setValue(__('First'));
				
				if ($this->_pagination->page > 1) {
					$li->setValue(
						Widget::Anchor(__('First'), $this->generateLink(array(
							'pg' => 1
						)))->generate()
					);
				}
				
				$ul->appendChild($li);
				
				// Previous:
				$li = new XMLElement('li');
				$li->setValue(__('&larr; Previous'));
				
				if ($this->_pagination->page > 1) {
					$li->setValue(
						Widget::Anchor(__('&larr; Previous'), $this->generateLink(array(
							'pg' => $this->_pagination->page - 1
						)))->generate()
					);
				}
				
				$ul->appendChild($li);
				
				// Summary:
				$li = new XMLElement('li', __('Page %s of %s', array(
					$this->_pagination->page,
					max($this->_pagination->page, $this->_pagination->pages)
				)));
				$li->setAttribute('title', __('Viewing %s - %s of %s entries', array(
					$this->_pagination->start,
					$this->_pagination->end,
					$this->_pagination->total
				)));
				$ul->appendChild($li);
				
				// Next:
				$li = new XMLElement('li');
				$li->setValue(__('Next &rarr;'));
				
				if ($this->_pagination->page < $this->_pagination->pages) {
					$li->setValue(
						Widget::Anchor(__('Next &rarr;'), $this->generateLink(array(
							'pg' => $this->_pagination->page + 1
						)))->generate()
					);
				}
				
				$ul->appendChild($li);
				
				// Last:
				$li = new XMLElement('li');
				$li->setValue(__('Last'));
				
				if ($this->_pagination->page < $this->_pagination->pages) {
					$li->setValue(
						Widget::Anchor(__('Last'), $this->generateLink(array(
							'pg' => $this->_pagination->pages
						)))->generate()
					);
				}
				
				$ul->appendChild($li);
				$this->Form->appendChild($ul);
			}
		}
	}
	
?>
