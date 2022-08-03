class config{
	private static $instance;

	public static function singleton(){
		if(!isset(self::$instance)){
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}
	
	private function __construct(){			
		/* MySQL DB connect settings */
		$this->db_host = 'mysql.mydomain.com';
		$this->db_user = 'myMySQLUser';
		$this->db_pass = 'superSecretPassword';
		$this->db_name = 'myAppDB';
	}
}
