<?php 

/**
*	Server Side Processing for DataTables ~1.10^
*	compatible with Codeigniter 3
*/
class SSP 
{
	/**
	* Declare global variables
	*
	*	@var array 	$request 	refers to $_POST
	*	@var array 	$table
	*	@var string $columns 	processed string columns
	*	@var array 	$_columns 	proxy array stored raw columns
	*	@var mixed 	$join
	*	@var mixed 	$where
	*	@var string $limit
	*	@var string $search
	*	@var integer $record_total
	*	@var integer $records_filtered
	*/
	private $request 	= array();
	private $table 		= array();
	private $columns	= "";
	private $_columns	= array();
	private $join 		= "";
	private $where 		= "";
	private $filter 	= "";
	private $order_by 	= "";
	private $limit 		= "";
	private $search 	= "";
	private $record_total = 0;
	private $records_filtered = 0;


	private $__table			= "";
	private $__column	= array();
	private $__columns	= array();
	private $__columns_search 	= array();
	private $__join 	= array();
	private $__where 	= array();
	private $__filter 	= array();
	private $__alias 	= array();

	/** 
	* @var string $sql
	*/
	public $sql = "";

	/**
	* 	@var string $hostname
	* 	@var string $username
	* 	@var string $password
	* 	@var string $database
	* 	@var string $connection
	*/

	private $hostname;
	private $username;
	private $password;
	private $database;
	private $connection;

	/**
	* Construct database connection strings
	*
	* 	@param string $hostname
	* 	@param string $username
	* 	@param string $password
	* 	@param string $database
	*/
	function __construct($hostname, $username="", $password="", $database="")
	{
		// check for $_REQUEST
		// if(empty($_REQUEST)) die('requires $_POST.');

		$this->request = $_POST;

		// check if $hostname is object or array
		// $hostname can be MySQL object
		if(gettype($hostname) == 'object'){
			$this->connection = $hostname;
			return;
		}

		// as of codeigniter 3
		if(gettype($hostname) == 'array'){
			$this->connection = $hostname[0]->conn_id;
			return;
		}

		$this->hostname = $hostname;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;

		// initiate connection
		$this->connection();
	}

	protected function connection()
	{
		// if $this->connection is empty which means MySQL passed already
		if(empty($this->connection)){
			// make database connection
			$this->connection = new mysqli($this->hostname, $this->username, $this->password, $this->database);
		}
		
		// identify if has error
		if (mysqli_connect_error()) {
		    die('Connection Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error());
		}

		// Change character set to utf8
		mysqli_set_charset($this->connection,"utf8");
	}

	/**
	* 	@param string $sql
	*
	* 	@return object
	*/
	protected function query($sql)
	{
		/**
		*	@var object $response
		*/
		$response = new stdClass;

		// make query then push to $response->data 
		$response->data = mysqli_query($this->connection, $sql);

		// push error to $response->error
		$response->error = mysqli_error($this->connection);

		return $response;
	}

	/**
	* 	@param string $sql
	*
	* 	@return array
	*/
	protected function result($sql)
	{
		// make query using callback
		$response = $this->query($sql);

		if(!empty($response->error)) die($response->error);

		if(empty($response->data)) return;

		// populate $list as object
		$list = array();
		foreach ($response->data as $key => $value) {
			$list[] = $value;
		}

		return $list;
	}

	/**
	*	@param callable $callback ($table, $columns, $join, $where)
	*
	* 	@return self instance
	*/	
	public function make(callable $callback)
	{
		/**
		* @var ArrayObject 	$table
		* @var ArrayObject 	$columns
		* @var ArrayObject 	$join
		* @var ArrayObject 		$where
		*/
		$table 		= new ArrayObject;
		$columns 	= new ArrayObject;
		$join 		= new ArrayObject;
		$where 		= new ArrayObject;

		// if(!is_callable($callback)) die 'Argument given not callable.';

		// implement callback
		$callback($table, $columns, $join, $where, $this);

		$this->table 	= $table[0];
		$this->_columns = $columns;
		$this->columns 	= $this->pluck($columns);

		// check if it has  join
		if($join != new ArrayObject){
			$this->join = $this->pluck($join, true);
		}

		$this->_where($where);
		$this->_filter();
		$this->_limit();

		// start query
		$this->sql .= "SELECT $this->columns FROM `$this->table` ";

		// join
		$this->sql .= $this->join;

		if(empty($this->records_total)){
			// get total of records fetched before where
			$this->records_total($this->sql);
		}

		// where and filter
		if(!empty($this->where) || !empty($this->filter)){

			$this->sql .= ' WHERE ';
			$this->sql .= $this->where;

			if(!empty($this->filter)){
				if(!empty($this->where)){
					$this->sql .= ' AND ' . $this->filter;
				}
				else{
					$this->sql .= $this->filter;	
				}
			}
			else{
				$this->sql .= $this->filter;	
			}
		}

		if(empty($this->records_filtered)){
			// get filtered records before limit
			$this->records_filtered($this->sql);
		}

		$this->sql .= $this->limit;

		return $this;
	}

	/**
	* 	@param mixed $data
	*
	* 	@return string 
	*/
	public function _output($data="")
	{
		if(empty($data)){
			$data = $this->result($this->sql);
		}

		// set HTTP header
		header('content-type:application/json; charset=utf-8');
		echo json_encode(
			array(
	            "sql"            => $this->sql,
	            "draw"            => $this->request['draw'],
	            "recordsTotal"    => $this->records_total,
	            "recordsFiltered" => $this->records_filtered,
	            "data"            => $this->data_output($data)
        	)
        );
        die;
	}

	/**
	* @return string
	*/
	public function display()
	{
		if(!empty($this->__table))
			return $this->output(true);
		return $this->sql;
	}

	/**
	*	@param mixed $data
	*/
	public function records_total($data)
	{
		if(gettype($data) == 'string'){
			$data = $this->result($data);
		}

		$this->records_total = count($data);
	}

	/**
	*	@param mixed $data
	*/
	public function records_filtered($data)
	{
		$search = $this->request['search']['value'];

		if(empty($search)){
			$this->records_filtered	= $this->records_total;
		}
		else{
			if(gettype($data) == 'string'){
				$data = $this->result($data);
			}

			$this->records_filtered = count($data);
		}
	}

	/**
	*	@param ArrayOject $data
	*/
	protected function _where($data)
	{
		/**
		* 	@var array $wdata
		*/

		$wdata = array();

		if(empty((array)$data)) return;

		for ($i=0; $i < count($data); $i++) { 
			foreach ($data[$i] as $key => $value) {
				$wdata[] = $key . '=\'' . $value . '\'';
			}
		}

		$this->where = ' ' . implode(' AND ', $wdata) . ' ';
	}

	/**
	*	@return integer
	*/
	protected function records_count()
	{
		/**
		*	@var integer $count
		*/
		$count = $this->result("SELECT COUNT(*) as 'count' FROM `$this->table`");
		return intval($count[0]['count']);
	}

	protected function _filter()
    {
    	/**
    	*	@var array 		$array
    	*	@var string 	$search
    	*	@var boolean 	$is_searchable
    	*/
    	$array = array();
    	$search = $this->request['search']['value'];

    	if(empty($search)) return;

    	for ($i=0; $i < count($this->request['columns']); $i++) { 
    		// check if columns is searchable
    		$is_searchable = $this->request['columns'][$i]['searchable'];
    		if($is_searchable){
    			$array[] = $this->_columns[$i] . ' LIKE "%' . $search . '%"';
    		}
    	}

    	$this->filter = ' ' . implode(' OR ', $array) . ' ';
    }

	/**
	*	@param array $data
	*	
	*	@return array
	*/
	protected function data_output($data)
	{
	    /**
	    * 	@var array 	$rows
	    * 	
	    * 	@return 	json
	    */
		$rows = array();

		for ($i=0; $i < count($data); $i++) { 
			$rows[] = $data[$i];
		}

		return $rows;
	}

	protected function _limit()
    {
    	/**
		* 	@var integer 	$start
		* 	@var integer 	$length
		*/
    	$start 	= $this->request['start'];
    	$length = $this->request['length'];

		$this->limit = " LIMIT ".intval($start).", ".intval($length) . ' ';
    }

	/**
	* @param array $array
	* @param bool $is_join
	*
	* @return string
	*/
	protected function pluck($array, $is_join=false)
	{
		/**
		* @var array $join
		* @var array $array converts ArrayObject to Array
		*/

		$array = (array)$array;

		if($is_join){

			$join = array();

			// convert ArrayObject to Array
			for ($i=0; $i < count($array); $i++) { 

				$_join = array();

				for ($a=0; $a < count($array[$i]); $a++) { 
					$_join[] = $array[$i][$a];
				}

				$join[] = "LEFT JOIN `$_join[0]` ON `$_join[0]`.`$_join[1]` = `$_join[2]`.`$_join[1]`";
			}

			return ' ' . implode(' ', $join) . ' ';
		}
		else{

			$columns = array();

			for ($i=0; $i < count($array); $i++) {

				// column alias
				if($array[$i] > 1) {

					$d = array();
					$d['_column'] = '`' . str_replace('.', '`.`', $array[$i][0]) . '`';
					$d['_alias'] = '\'' . $array[$i][1] . '\'';

					extract($d);

					// update $_columns
					$this->_columns[$i] = $_alias;

					$columns[] = $_column . ' AS ' . $_alias;
				}
				// normal columns
				else{
					$columns[] = $array[$i];
				}
			}
			
			return ' ' . implode(', ', $columns ) . ' ';
		}
	}

	public function table($table)
	{
		$this->__table = " `$table` ";
		return $this;
	}

	public function column($column_name, $alias="")
	{
		if(empty($alias)){
			$this->__columns[] = $column_name;
			$this->__columns_search[] = $column_name;

			if(strpos($column_name, '*') !== false){
				$this->__column[] = " `" . str_replace('.', '`.', $column_name) . " ";
			}
			else{
				$this->__column[] = " `" . str_replace('.', '`.`', $column_name) . "` ";
			}
		}
		else{
			$this->__alias[] = $alias;
			$this->__columns[] = $alias;
			$this->__columns_search[] = $column_name;
			$this->__column[] = " " . str_replace('.', '.', $column_name) . " AS '$alias' ";
		}

		return $this;
	}

	public function join($join_table, $column_name, $table="")
	{
		// alias

		if(strpos($join_table, ' as ') !== false){
			$e = explode(' as ', $join_table);
			$join_table = "`$e[0]` as $e[1]";
		}
		else{
			$join_table = "`$join_table`";
		}

		/**
		* $column_name has 2 options as follows:
		* 1. 	'user_type_id'
		* 2.	`user`.`user_type_id` = `user_type`.`user_type_id`
		*/


		$join = "$join_table.`$column_name` = `$table`.`$column_name`";

		if(strpos($column_name, '=') !== false){
			$join = $column_name;
		}

		$this->__join[] = " LEFT JOIN $join_table ON $join ";
		return $this;
	}

	public function where($column_name, $value)
	{
		$this->__where[] = " `" . str_replace('.', '`.`', $column_name) . "`  = '$value' ";
		return $this;
	}

	public function where_not($column_name, $value)
	{
		$this->__where[] = " `" . str_replace('.', '`.`', $column_name) . "`  != '$value' ";
		return $this;
	}

	public function where_not_in($column_name, $value)
	{
		// $value should be array
		if(gettype($value) == 'array'){
			$where_not_in = implode(',', $value);
			$this->__where[] = " `" . str_replace('.', '`.`', $column_name) . "` NOT IN ('$where_not_in') ";
		}

		return $this;
	}

	public function where_in($column_name, $value)
	{
		// $value should be array
		if(gettype($value) == 'array'){
			$where_in = implode(',', $value);
			$this->__where[] = " `" . str_replace('.', '`.`', $column_name) . "` IN ($where_in) ";
		}

		return $this;
	}

	protected function filter()
	{
		$search = $this->request['search']['value'];

		if(empty($search)) return;

		foreach ($this->__columns_search as $key => $column_name) {
			if(in_array($column_name, $this->__alias)){
				$this->__filter[] = " '" . str_replace('.', '\'.\'', $column_name) . "'  LIKE '%$search%' ";
			}
			else{
				$this->__filter[] = " `" . str_replace('.', '`.`', $column_name) . "`  LIKE '%$search%' ";				
			}
		}
	}

	public function order_by( $column_name, $sort )
	{
		if( empty($this->order_by) ){
			$this->order_by .= ' ORDER BY ';
			$this->order_by .= " `" . str_replace('.', '`.`', $column_name) . "` " . $sort . "";
		}
		else{
			$this->order_by .= ", `" . str_replace('.', '`.`', $column_name) . "` " . $sort . "";
		}

		$this->sql .= $this->order_by;

		return $this;
	}

	public function output($display=false)
	{
		$this->sql = 'SELECT' . implode(',', $this->__column) . 'FROM' . $this->__table . implode('', $this->__join);

		// set filter
		$this->filter();

		// where and filter

		if(!empty($this->__where) || !empty($this->__filter)){
			$this->sql .= 'WHERE';
		}

		if(!empty($this->__filter)){
			$this->sql .= ' (' . implode('OR', $this->__filter) . ') ';
		}

		if(!empty($this->__where)){
			if(!empty($this->__filter)){
				$this->sql .= 'AND';
			}
			
			$this->sql .= implode('AND', $this->__where);
		}

		// set total records fetched
		$this->records_total($this->sql);

		// set filtered records
		$this->records_filtered($this->sql);

		// set order by
		$this->sql .= $this->order_by;

		// get limit
		$this->_limit();

		// set limit
		$this->sql .= $this->limit;

		if($display){
			return $this->sql;
		}

		$this->_output();
	}

	/**
	* 	@var callable $callback
	*/
	public function then(callable $callback)
	{
		$callback($this);
	}
}

?>