<?php
class widget extends record{
	private static $instance = array();

	function __construct($id=null,$data=null){
		parent::__construct(table::singleton('widget_table'),$id,$data);
	}

	public static function singleton($id,$data=null){
		if(!isset(self::$instance[$id])){
			$c = __CLASS__;
			self::$instance[$id] = new $c($id,$data);
		}
		return self::$instance[$id];
	}

	// Prevent cloning
	public function __clone(){
		trigger_error('Clone is not allowed.', E_USER_ERROR);
	}
}
?>
