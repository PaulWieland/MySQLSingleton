<?php
class MySQL_singleton{
	private static $_instance;
	protected $_link;
	protected $_result;

	protected function __construct(){
		// Load the configuration object
		$config = config::singleton();
		
		$this->_link = mysql_connect($config->db_host,$config->db_user,$config->db_pass) or $this->error("Couldn't connect to the MySQL server.");
		mysql_select_db($config->db_name) or $this->error("Couldn't select database ".$config->db_name.".");
	}

	public static function singleton(){
		if(!isset(self::$_instance)){
			$c = __CLASS__;
			self::$_instance = new $c();
		}
		return self::$_instance;
	}

	protected function execute($query,$debug=false){
		global $counter;
		$counter++;
		$this->_result = mysql_query($query, $this->_link) or $this->error(mysql_error()." \n QUERY: ".$query);
		// config::logException($counter.":	".$query);
		// echo $counter." ".$query."\n";
		return $this->_result;
	}
	
	protected function fetch($result=null){
		if(!$result)
			return mysql_fetch_object($this->_result);
		else
			return mysql_fetch_object($result);
	}
	
	public function affectedRows(){
		return mysql_affected_rows($this->_link);
	}
	public function numRows(){
		return mysql_num_rows($this->_result);
	}
	
	
	public function error($msg){
		throw new Exception($msg);
	}

	public function warn($msg){
		echo $msg;
	}
	
	protected function merge($obj){
		foreach((is_object($obj) ? array_keys(get_object_vars($obj)) : array()) as $property){
			$this->$property = $obj->$property;
		}
	}

	protected function link($obj){
		foreach((is_object($obj) ? array_keys(get_object_vars($obj)) : array()) as $property){
			$this->$property =& $obj->$property;
		}
	}

	public function esc($in){
		return mysql_real_escape_string($in);
	}
	
	function __destruct(){
	}
}

class table extends MySQL_singleton{
	private static $_instance;
	protected $_updateable = false; // Boolean for whether or not it is possible to update the table. This is true if a primary key is set.
	protected $_name;
	protected $_primary_keys = array(); // The table's primary key field names
	protected $_auto_increment; // The table's auto increment field
	protected $_fields = array(); // The fields in the table
	// protected $_records = array();

	protected function __construct($table){
		parent::__construct();
		if($table != $this->esc($table)) $this->error('Invalid table name given: '.$table);

		/* Check to make sure a valid table name was given */
		if(mysql_num_rows($this->execute("SHOW TABLES LIKE '$table'")) != 1)
			$this->error("Invalid table name given: $table");

		$this->_name = $table;

		/* Lookup all of the table's fields and their definitions
		Also figure out which fields are in the table's primary key & which is auto_increment */
		$this->execute("SHOW COLUMNS FROM `$table`");
		while($field = $this->fetch()){
			if($field->Key == 'PRI'){
				$this->_primary_keys[$field->Field] = $field->Field;
				$this->_updateable = true;
			}
			if($field->Extra == 'auto_increment'){
				$this->_auto_increment = $field->Field;
			}
			$this->_fields[strtolower($field->Field)] = $field;
		}
	}
	
	public static function singleton($table){
		if(!isset(self::$_instance[$table])){
			$c = __CLASS__;
			self::$_instance[$table] = new $c($table);
		}
		return self::$_instance[$table];
	}
	
	public function getName(){
		return $this->_name;
	}
	public function getPrimaryKeys(){
		return $this->_primary_keys;
	}
	public function getAutoIncrement(){
		return $this->_auto_increment;
	}
	public function getFields(){
		return $this->_fields;
	}
	public function isUpdateable(){
		return $this->_updateable;
	}
	
	// public function &getRecord($id){
	//  return $this->_records[$id];
	// }
	// public function setRecord($record){
	//  $this->_records[$record->getPrimaryKey()] = $record;
	// }
	
	// Deletes a record based on primary key
	public function deleteRecord($id){
		if(count($this->_primary_keys) > 1 && !is_array($id)){
			throw new Exception("Cannot delete record, table has multiple primary keys and passed value was a string.");
		}
		
		if(is_numeric($id) || is_string($id)){
			$id = array(current(array_values($this->_primary_keys))=>$id);
		}
		
		if(!is_array($id)){
			throw new Exception("Invalid primary key. Primary key must be an array.");
		}

		$values = array();

		foreach($this->_primary_keys as $key){
			if(!isset($id[$key])) throw new Exception("Primary key ($key) value is missing from \$id argument.");
			$values[] = "`".$key."` = '".$this->esc($id[$key])."'";
		}
		
		$this->execute("DELETE FROM `".$this->_name."` WHERE ".implode(' AND ',$values)." LIMIT 1");
		record::unsetInstance($this->_name,implode("",$id));
		return $this->affectedRows();
	}
	
	public function search($field,$value){
		$retval = array();
		// Make sure it's a valid field
		if(!$this->_fields[strtolower($field)]) return $retval;
		$this->execute("SELECT *".(!empty($this->_primary_keys) ? ", CONCAT(`".implode('`,`',$this->_primary_keys)."`) as _primary_key":"")." FROM `".$this->_name."` WHERE `".$field."` ".(strstr($value,'%') ? 'like' : '=')." '".$this->esc($value)."'");
		if(!empty($this->_primary_keys)){
			while($record = $this->fetch()){
				$key = $record->_primary_key;
				unset($record->_primary_key);
				$retval[$key] = record::singleton($this,$key,$record);
			}
		}else{
			while($record = $this->fetch()){
				$retval[] = new record($this,'',$record);
			}
		}
		
		return $retval;
	}
}

class record extends MySQL_singleton{
	private static $_instance = array();
	protected $_updated = false; // Boolean for whether or not the record has been updated
	protected $_updateable = false; // Boolean for whether or not it is possible to update the record. This is true if no primary key is set.
	protected $_mode; // enum for whether we're in insert or update mode
	protected $_table;
	protected $_primary_keys = array(); // The table's primary key field name
	protected $_original_key_values = array(); // The record's original primary key=>values. This is needed if the primary key is updated.
	protected $_auto_increment; // The table's auto_increment field name
	protected $_fields = array(); // The fields in the table
	
	function __construct(table $table,$id=null,$data=null){
		parent::__construct();
		
		$this->_table =& $table->getName();
		$this->_primary_keys =& $table->getPrimaryKeys();
		$this->_auto_increment =& $table->getAutoIncrement();
		$this->_fields =& $table->getFields();
		
		if(isset($id)){
			/* If an ID is passed, assume it's an existing record (may not be the case, tested with an SQL statement below) */
			$this->_mode = 'update';
			
			/* If there is only one primary key on this table... */
			if(count($this->_primary_keys) == 1){
				if(is_numeric($id) || is_string($id)){
					$id = array(current(array_values($this->_primary_keys))=>$id);
				}else{
					throw new Exception("Table ($this->_table) has a single primary key field. Second argument for \$id is not a string.");
				}

			/* If there is more than one primary key on this table... */
			}elseif(count($this->_primary_keys) > 1){
				if(!is_array($id)) throw new Exception("Table ($this->_table) has multiple primary key fields. Second argument (\$id) must be an array keyed by all primary key field names.");
				foreach($this->_primary_keys as $field){
					if(!isset($id[$field])) throw new Exception("Table ($this->_table) has multiple primary key fields. Field ($field) missing from the \$id array.");
				}
			}
		}else{
			/* we're creating a new record from scratch */
			$this->_mode = 'insert';
		}
		
		
		if(is_object($data)){
			/* Data was provided to populate the record with, it is assumed there is no need to query the DB for the record information */
			$this->merge($data);
			/* Save the primary key values in case they are overwritten by setProperty */
			$this->updateOriginalKeyValues();
		}elseif(!empty($id)){
			/* Get the data for the record if it wasn't passed in */
			$this->execute("SELECT * FROM `$this->_table` WHERE ".$this->buildPrimaryKeyWhereClause($id)." LIMIT 1");

			/* No records were found, switch back to insert and assign the primary key values to the object */
			if($this->numRows() == 0){
				foreach($this->_primary_keys as $key){
					$this->$key = $id[$key];
				}
				$this->_mode = 'insert';
			}else{
				$this->merge($this->fetch());
				/* Save the primary key values in case they are overwritten by setProperty */
				$this->updateOriginalKeyValues();
			}
		}
			
		if($table->isUpdateable()) $this->_updateable = true;
	}
	
	/* In order to use singleton, $id must be set */
	public static function singleton(table $table,$id,$data=null){
		if(is_array($id)){
			foreach($table->getPrimaryKeys() as $key){
				$primary_key .= $id[$key];
			}
		}else{
			$primary_key = $id;
		}
		
		if(!isset(self::$_instance[$table->getName()][$primary_key])){
			$c = __CLASS__;
			self::$_instance[$table->getName()][$primary_key] = new $c($table,$id,$data);
		}
		return self::$_instance[$table->getName()][$primary_key];
	}
	
	// Get a plain copy of this object
	public function getPlain(){
		$r = new stdClass;
		foreach($this as $key=>$value){
			if($key{0}!='_')
				$r->$key = $value;
		}
		return $r;
	}
	
	public function getMode(){
		return $this->_mode;
	}
	
	public function getPrimaryKey(){
		$retval = '';
		foreach($this->_original_key_values as $value){
			if($value)
				$retval .= $value;
			else
				return '';
		}
		return $retval;
	}
	
	private function buildPrimaryKeyWhereClause(Array $id){
		$values = array();

		foreach($this->_primary_keys as $key){
			if(!isset($id[$key])) throw new Exception("Primary key ($key) value is missing from \$id argument.");
			$values[] = "`".$key."` = '".$this->esc($id[$key])."'";
		}
		
		return implode(' AND ',$values);
	}
	
	private function updateOriginalKeyValues(){
		$this->_original_key_values = array(); // Reset the _original_key_values array
		foreach($this->_primary_keys as $key){
			$this->_original_key_values[$key] = $this->$key;
		}
	}

	public function setProperty($property,$value){
		if($this->$property != $value){
			$this->_updated = true;
			$this->$property = $value;
		}
		
		return true;
	}
	
	/* UPDATES the record */
	public function update(){
		if(!$this->_updateable || $this->_mode != 'update') return false;

		$values = array();
		$query = "UPDATE $this->_table SET ";
		foreach($this->_fields as $field){
			if(!isset($this->{$field->Field})) continue;
			$values[] = '`'.$field->Field.'` = '.($this->{$field->Field}===null && $field->Null=='YES'?'null':"'".$this->esc($this->{$field->Field})."'");
		}
		
		// Bail out if no values were set
		if(empty($values)) return false;
		
		// Append the field / value pairs to the query
		$query .= implode(", ",$values);
		
		// Set the where
		$query .= " WHERE ".$this->buildPrimaryKeyWhereClause($this->_original_key_values)." LIMIT 1";
		$this->execute($query);

		if(mysql_affected_rows($this->_link) == 1){
			/* The update worked, _original_key_values should now be updated to have the new values */
			$this->updateOriginalKeyValues();
			return true;
		}else{
			return true;
			// Fix this at some point - it should return false if the update didnt affect anything
			return false;
		}
	}
	
	/* INSERTS the record */
	public function insert(){
		if($this->_mode != 'insert') return false;
		
		$fields = array();
		$values = array();
		$query = "INSERT INTO $this->_table ";
		foreach($this->_fields as $field){
			if($field->Extra == 'auto_increment') continue; // Don't insert the auto increment field
			if(!isset($this->{$field->Field})) continue;
			$fields[] = $field->Field;
			$values[] = ($this->{$field->Field}===null && $field->Null=='YES'?'null':"'".$this->esc($this->{$field->Field})."'");
		}
		
		// Append the field / value pairs to the query
		$query .= '(`'.implode('`,`',$fields).'`) VALUES ';
		$query .= '('.implode(", ",$values).')';
		$this->execute($query);

		if(mysql_affected_rows($this->_link) == 1){
			if($this->_auto_increment){
				$this->{$this->_auto_increment} = mysql_insert_id($this->_link);
			}
			
			/* The insert worked, _original_key_values should now be updated to have the new values */
			$this->updateOriginalKeyValues();
			
			self::$_instance[$this->_table][$this->getPrimaryKey()] = $this;
			
			$this->_mode = 'update'; // In case save() is called later
			return true;
		}else{
			return false;
		}
	}
	
	public function save(){
		if($this->_mode == 'insert')
			return $this->insert();

		if($this->_mode == 'update'){
			if($this->_updated){
				return $this->update();
			}else{
				/* If the object wasn't updated then there was no need to write anything to
				 the db. I consider save() to have been succesfull.*/
				return true;
			}
		}
		
		return false;
	}
	
	/* Delete the record from the DB and unset the corresponding instance */
	public function delete(){
		$this->execute("DELETE FROM `".$this->_table."` WHERE ".$this->buildPrimaryKeyWhereClause($this->_original_key_values)." LIMIT 1");
		return $this->affectedRows();
		unset(self::$_instance[$this->_table][$this->getPrimaryKey()]);
	}
	
	/* If table::deleteRecord is called, the record instance must also be unset */
	public function unsetInstance($table,$key){
		unset(self::$_instance[$table][$key]);
	}

	function __destruct(){
	}
}
?>
