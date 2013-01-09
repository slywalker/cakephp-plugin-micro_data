<?php
App::uses('AppShell', 'Console/Command');
App::uses('ConnectionManager', 'Model');
App::uses('CakeSchema', 'Model');
App::uses('Inflector', 'Utility');

class SchemaShell extends AppShell {

	public $tasks = array('DbConfig', 'Model');

	public $Schema;

	public $Db;

	public $connection = 'default';

	public $MicroData;

	const SCHEMA_RDF_ORG_JSON = 'http://schema.rdfs.org/all.json';

	protected $_schemaMap = array(
		'Primary' => array('type' => 'integer', 'key' => 'primary'),
		'ForeignKey' => array('type' => 'integer'),
		'Text' => array('type' => 'string', 'default' => '', 'null' => false),
		'URL' =>  array('type' => 'string', 'default' => '', 'null' => false),
		'Date' => 'date',
		'Time' => 'time',
		'DateTime' => 'datetime',
		'Boolean' => array('type' => 'boolean', 'default' => false, 'null' => false),
		'Integer' => array('type' => 'integer', 'default' => 0, 'null' => false),
		'Float' => array('type' => 'float', 'length' => '5,2', 'default' => 0, 'null' => false)
	);

	public function startup() {
		$this->_welcome();
		$this->out('MicroData Schema Shell');
		$this->hr();

		if (!$this->MicroData = Cache::read('micro_data')) {
			$this->out('<info>' . __d('micro_data', 'Downloading %s', self::SCHEMA_RDF_ORG_JSON) . '</info>');
			if ($json = file_get_contents(self::SCHEMA_RDF_ORG_JSON)) {
				$this->MicroData = json_decode($json);
				Cache::write('micro_data', $this->MicroData);
			}
		}
	}

	public function main() {
		if (!config('database')) {
			$this->out(__d('micro_data', 'Your database configuration was not found.'));
			$this->args = null;
			return $this->DbConfig->execute();
		}

		$this->connection = $this->DbConfig->getConfig();
		$this->Db = ConnectionManager::getDataSource($this->connection);

		if (!$this->MicroData) {
			$this->out(__d('micro_data', '%s was not found.', self::SCHEMA_RDF_ORG_JSON));
			exit(0);
		}

		$type = $this->in(__d('micro_data', 'Schema Type: [Q]uit'), null, 'q');
		if ($type === 'q') {
			exit(0);
		}

		$table = $this->in(__d('micro_data', 'Table Name:'), null, Inflector::tableize($type));
		if ($this->_verify($type, $table)) {

			$fields = $this->_createSchema($type);

			$this->Schema = new CakeSchema(array('connection' => $this->connection));
			$this->Schema->build(array($table => $fields));

			if ($this->_verifyOverwrite($table)) {
				$this->_createTable($table);
			}
		}

		$this->hr();
		$this->main();
	}

	protected function _verify($type, $table) {
		$this->hr();
		$this->out(__d('micro_data', 'The following schema will be created:'));
		$this->hr();
		$this->out(__d('micro_data', 'Database Config: %s', $this->connection));
		$this->out(__d('micro_data', 'Schema Type:     %s', $type));
		$this->out(__d('micro_data', 'Table Name:      %s', $table));
		$this->hr();
		$looksGood = $this->in(__d('micro_data', 'Look okay?'), array('y', 'n'), 'y');
		return (strtolower($looksGood) === 'y');
	}

	protected function _verifyOverwrite($table) {
		$tables = $this->Model->getAllTables($this->connection);
		if (in_array($table, $tables)) {
			$allow = $this->in(
				__d('micro_data', 'Table "%s" is alredy exists. Allow overwrite?', $table),
				array('y', 'n'),
				'n'
			);
			if ($allow !== 'y') {
				return false;
			}
			$this->Db->execute($this->Db->dropSchema($this->Schema), array('log' => false));
		}
		return true;
	}

	protected function _createSchema($type) {
		$dataTypes = $this->MicroData->datatypes;
		$dataProperties = $this->MicroData->properties;
		$typeProperties = $this->MicroData->types->{$type}->properties;

		$fields = array('id' => $this->_schemaMap['Primary']);

		foreach ($typeProperties as $name) {
			$ranges = $dataProperties->{$name}->ranges;
			$field = Inflector::underscore(str_replace(array('ID', 'POS'), array('Id', 'Pos'), $name));
			if (count($ranges) === 1 && isset($dataTypes->{$ranges[0]})) {
				$type = $dataTypes->{$ranges[0]}->label;
				$fields[$field] = $this->_schemaMap[$type];
			} else {
				$fields[$field] = (array)$this->_schemaMap['ForeignKey'] + array(
					'comment' => implode(', ', $ranges)
				);
			}
		}
		$fields['created'] = 'datetime';
		$fields['updated'] = 'datetime';

		return $fields;
	}

	protected function _createTable($table) {
		try {
			$this->Db->execute(
				$this->Db->createSchema($this->Schema), array('log' => false)
			);
		} catch (Exception $e) {
			$this->out(
				'<error>' .
				__d(
					'micro_data',
					'Fixture creation for "%s" failed "%s"',
					$table,
					$e->getMessage()
				) .
				'</error>'
			);
			return false;
		}
		$this->out(
			'<success>' .
			__d('micro_data', 'Create Table "%s" complete!', $table) .
			'</success>'
		);
		return true;
	}

}