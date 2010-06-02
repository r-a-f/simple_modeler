<?php
/**
* Simple_Modeler
*
* @package		Simple_Modeler
* @author			thejw23
* @copyright		(c) 2009 thejw23
* @license		http://www.opensource.org/licenses/isc-license.txt
* @version		1.0 for KohanaPHP 3.x
* @last change		first beta version
* 
* @NOTICE			table columns should be different from class varibales/methods names
* @NOTICE			ie. having table column 'timestamp' or 'skip' may (and probably will) lead to problems
*  
* modified version of Auto_Modeler by Jeremy Bush, 
* class name changed to prevent conflicts while using original Auto_Modeler 
*/
class SimpleModeler extends Model 
{
	// The database table name
	protected $table_name = '';
	
	//primary key for the table
	protected $primary_key = 'id';
	
	//id hash field
	protected $hash_field = '';
	protected $hash_suffix = '';
	 	
	//if true all fields will be trimmed before save
	protected $auto_trim = FALSE;

	// store single record database fields and values
	protected $data = Array();
	protected $data_original = Array();
		
	// array, 'form field name' => 'database field name'
	public $aliases = Array(); 

	// skip those fields from save to database
	public $skip = Array ();

	// timestamp fields, they will be auto updated on db update
	// update is only if table has a column with given name
	public $timestamp = Array('time_stamp');
	
	//timestamp fields updated only on db insert
	public $timestamp_created = Array('time_stamp_created');

	//type of where statement: and_where, or_where, like, orlike...
	public $where = 'and_where';
	
	//fetch only those fields, if empty select all fields
	public $select = '*';

	//array with offset and limit for limiting query result
	public $limit;
	public $offset = 0; 
	
	//db result object type
	public $result_object = 'stdClass'; //defaults, arrays: MYSQL_ASSOC objects: stdClass 

	/**
	* Constructor
	*
	* @param integer|array $id unique record to be loaded	
	* @return void
	*/
	public function __construct($id = FALSE)
	{
		parent::__construct();

		if ($id != FALSE)
		{
			$this->load($id);
		}
		
		$this->load_columns();   
	}
		
	/**
	* Return a static instance of Simple_Modeler.
	* Useful for one line method chaining.	
	*
	* @param string $model name of the model class to be created
	* @param integer|array $id unique record to be loaded	
	* @return object
	*/
	public static function factory($model, $id = FALSE)
	{
		$model = empty($model) ? __CLASS__ : 'Model_'.ucwords($model);
		return new $model($id);
	}
	
	/**
	* Create an instance of Simple_Modeler.
	* Useful for one line method chaining.	
	*
	* @param string $model name of the model class to be created
	* @param integer|array $id unique record to be loaded	
	* @return object
	*/
	public static function instance($model, $id = FALSE)
	{
		static $instance;
		$model = empty($model) ? __CLASS__ : ucwords($model).'_Model';
		// Load the Simple_Modeler instance
		empty($instance) and $instance = new $model($id);
		
		//make sure that instance reflect passed model		
		if ( ! $instance instanceof $model)
			return  new $model($id);
			 
		return $instance;
	}
	
	/**
	* Generates user fiendly $data array with table columns	
	*
	* @return string
	*/
	public function generate_data() 
	{
		$out = "";
		
		//get columns from table
		$columns = $this->explain();
		
		if (!empty($columns))
		{
			$out .= '<pre>table: '.$this->table_name."<br />";
			$out .= 'protected $data = array(<br />';
			foreach ($columns as $column => $type)
			{
				$out .= "\t\t'".$column."' => '',<br />"; 
			}
			//remove last comma
			$out = rtrim($out,',<br />');
			$out .= "<br />\t\t);</pre>";	
		}
		
		//return formatted html code
		return $out;
	}

	/**
	* Shows table name of the loaded model
	*	
	* @return string
	*/
	public function get_table_name() 
	{
		return $this->table_name;
	}

	/**
	*  Allows for setting data fields in bulk	
	*
	* @param array $data data passed to $data
	* @return object
	*/
	public function set_fields($data)
	{
		//make sure that table columns are loaded
		//$this->load_columns();

		//assign new valuse to current data
		foreach ($data as $key => $value)
		{
			$key = $this->check_alias($key);

			if (array_key_exists($key, $this->data))
			{
				//skip field not existing in current table
				($this->auto_trim) ? $this->data[$key] = trim($value) : $this->data[$key] = $value;
			}
		}
		
		return $this;
	}

	/**
	*  Saves the current $data to DB	
	*
	* @return mixed
	*/
	public function save()
	{
		//make sure that table columns are loaded
		//$this->load_columns();

		// $data_to_save=$this->data;
		$data_to_save = array_diff_assoc($this->data, $this->data_original);

		// if no changes, quit
		if (empty($data_to_save))
		{
			return NULL;
		}

		$this->check_timestamp(& $data_to_save, $this->loaded());
		$this->check_skip(& $data_to_save);

		// Do an update
		if ($this->loaded())
		{ 
			if (!empty($data_to_save))
				return count(db::update($this->table_name)->set($data_to_save)->where(array($this->primary_key, '=', $this->data[$this->primary_key]))->execute($this->_db));
				//return count($this->db->update($this->table_name, $data_to_save, array($this->primary_key => $this->data[$this->primary_key])));
		}
		else // Do an insert
		{
			$id = db::insert($this->table_name)->values($data_to_save)->execute($this->_db)->insert_id() ;
			//$id = $this->db->insert($this->table_name, $data_to_save)->insert_id();
			$this->data[$this->primary_key] = $id;
			$this->data_original = $this->data;
			
			if ($id AND !empty($this->hash_field))
			{
				//$this->db->update($this->table_name, array($this->hash_field => sha1($this->table_name.$id.$this->hash_suffix)), array($this->primary_key => $id));
				db::update($this->table_name)->set(array($this->hash_field => sha1($this->table_name.$id.$this->hash_suffix)))->where(array($this->primary_key, '=', $this->data[$this->primary_key]))->execute($this->_db);
			}
			
			return ($id);
		}
		return NULL;
	}
	
	/**
	*  Set the DB results object type	
	*
	* @param string $object type or returned object
	* @return object
	*/
	public function set_result($object = stdClass) 
	{
		$this->result_object = $object;
		return $this; 
	}
	
	//reset settings
	public function reset()
	{
		$this->where = 'where';
		$this->select = '*';
		$this->limit = '';
		$this->offset = 0; 
		$this->result_object = 'stdClass';
		return $this; 
	}
	
	/**
	* load single record based on unique field value	
	*
	* @param array|integer $value column value
	* @param string $key column name  	 
	* @return object
	*/
	public function load($value, $key = NULL)
	{
		(empty($key)) ? $key = $this->primary_key : NULL;

		//make sure that table columns are loaded
		//$this->load_columns();
		
			//get data
			//if value is an array, make where statement and load data
			if (is_array($value))
			{
				$data = db::select($this->select)->from($this->table_name)->where($value)->execute($this->_db);
				//$data = $this->db->select($this->select)->$type($value)->get($this->table_name)->result(TRUE);
			}
			else //else load by default ID key
			{
				$data = db::select($this->select)->from($this->table_name)->where(array($key, '=', $value))->execute($this->_db);
				//$data = $this->db->select($this->select)->$type(array($key => $value))->get($this->table_name)->result(TRUE);
			}

			// try and assign the data
			if (count($data) === 1 AND $data = $data->current())
			{
				// set original data
				$this->data_original = (array) $data;
				// set current data
				$this->data = $this->data_original; 
			}
		
			return $this;
	}
	
	/**
	*  Returns single record without using $data		
	*
	* @param array|integer $value column value
	* @param string $key column name  	
	* @return mixed
	*/
	public function fetch_row($value, $key = NULL) 
	{
		(empty($key)) ? $key = $this->primary_key : NULL;
				
			// get data
			//if value is an array, make where statement and load data
			if (is_array($value))
			{
				$data = $data = db::select($this->select)->from($this->table_name)->where($value)->execute($this->_db);
				//$data = $this->db->select($this->select)->$type($value)->get($this->table_name)->result(TRUE, $this->result_object);
			}
			else //else load by default ID key
			{
				$data = $data = db::select($this->select)->from($this->table_name)->where(array($key, '=', $value))->execute($this->_db);
				//$data = $this->db->select($this->select)->$type(array($key => $value))->get($this->table_name)->result(TRUE, $this->result_object);
			}

			// try and assign the data
			if (count($data) === 1 AND $data = $data->current())
			{				
				return $data;
			}

			return NULL;
	}
	

	/**
	* Deletes from db current record or condition based records 	
	*
	* @param array $what data to be deleted
	* @return mixed
	*/ 
	public function delete($what = array())
	{
		//delete by conditions
		if (( ! empty($what)) AND (is_array($what)))
		{
			//delete  based on passed conditions
			//return $this->db->delete($this->table_name, $what);
			return db::delete($this->table_name)->where($what)->execute($this->_db);
		}
		//else delete current record
		elseif (intval($this->data[$this->primary_key]) !== 0) 
		{
			//if no conditions and data is loaded -  delete current loaded data by ID
			//return $this->db->delete($this->table_name, array($this->primary_key => $this->data[$this->primary_key]));
			return db::delete($this->table_name)->where(array($this->primary_key, '=', $this->data[$this->primary_key]))->execute($this->_db);
		}
	}

	/**
	*  Fetches all records from the table	
	*
	* @param string $order_by ordering
	* @param string $direction sorting	
	* @return mixed
	*/
	public function fetch_all($order_by = NULL, $direction = 'ASC')
	{
		(empty($order_by)) ? $order_by = $this->primary_key : NULL;
		
			//if there are limits
			//if ( ! empty($this->limit)) 
			//{
				//return $this->db->select($this->select)->limit($this->limit,$this->offset)->orderby($order_by, $direction)->get($this->table_name)->result(TRUE, $this->result_object);
				$query =  db::select($this->select)->order_by($order_by, $direction);
				
				if ( ! empty($this->limit)) 
				{
					$query->limit($this->limit)->offset($this->offset);
				}
				
				$query->from($this->table_name)->execute($this->_db);
			//}
			//else get all records from table
			/*else
			{
				//return $this->db->select($this->select)->orderby($order_by, $direction)->get($this->table_name)->result(TRUE, $this->result_object);
				return db::select($this->select)->order_by($order_by, $direction)->from($this->table_name)->execute($this->_db);
			} */
		
		return NULL;
	} 
	
	/**
	*  Fetches some records from the table	
	*
	* @param array $where where conditions	
	* @param string $order_by ordering
	* @param string $direction sorting	
	* @return mixed
	*/
	public function fetch_where($wheres = array(), $order_by = NULL, $direction = 'ASC')
	{	
		(empty($order_by)) ? $order_by = $this->primary_key : NULL;
		
		$type = $this->where;
		
		if (! is_array($where))
			return FALSE;
			
				//return $this->db->select($this->select)->$type($where)->limit($this->limit,$this->offset)->orderby($order_by, $direction)->get($this->table_name)->result(TRUE, $this->result_object);
				//return db::select($this->select)->limit($this->limit)->offset($this->offset)->order_by($order_by, $direction)->$type($where[0],$where[1],$where[2])->from($this->table_name)->execute();
				$query = db::select($this->select)->order_by($order_by, $direction);

				if ( ! empty($this->limit))
				{ 				
					$query->limit($this->limit)->offset($this->offset);
				}
		
				foreach ($wheres as $where)
					$query->{$this->where}($where[0], $where[1], $where[2]);
		
				return $query->from($this->table_name)->execute($this->_db);
			//}
			//else get all records from table based on passed conditions
			/*else
			{ 
				//return $this->db->select($this->select)->$type($where)->orderby($order_by, $direction)->get($this->table_name)->result(TRUE, $this->result_object);
				//return db::select($this->select)->order_by($order_by, $direction)->$type($where[0],$where[1],$where[2])->from($this->table_name)->execute();
				$query = db::select($this->select)->order_by($order_by, $direction)->as_object('Model_'.inflector::singular(ucwords($this->_table_name)));
		
				foreach ($wheres as $where)
					$query->{$this->where}($where[0], $where[1], $where[2]);
		
				return $query->from($this->table_name)->execute($this->_db);
			}*/
		
		return NULL;
	}

	/**
	*  Run query on DB	
	*
	* @param string $sql query to be run
	* @return object
	*/
	public function query($sql)
	{
		//return $this->db->query($sql)->result(TRUE, $this->result_object);
		return db::query($sql)->execute($this->_db);
	} 
		
	/**
	*  Checks if given key is an alias and if so then points to aliased field name	
	*
	* @param string $key key to be checked
	* @return boolean
	*/
	 public function check_alias($key)
	 {
		return array_key_exists($key, $this->aliases) === TRUE ? $this->aliases[$key] : $key;
	 }
	 
	/**
	*  Checks if given key is a timestamp and should be updated	
	*
	* @param string $key key to be checked
	* @return array
	*/
	 public function check_timestamp($data, $create = FALSE)
	 {
		//update timestamp fields with current datetime
		if ( ! $create)
		{
			if ( ! empty($this->timestamp) AND is_array($this->timestamp))
				foreach ($this->timestamp as $field)
					if (array_key_exists($field, $this->data_original)) 
					{
						$data[$field] = date('Y-m-d H:i:s');
					}
		}
		else
		{
			if ( ! empty($this->timestamp_created) AND is_array($this->timestamp_created))
				foreach ($this->timestamp_created as $field)
					if (array_key_exists($field, $this->data_original))
					{
						$data[$field] = date('Y-m-d H:i:s');
					}
		}
		return $data;
	 }
	 
	/**
	*  Checks if given key should be skipped	
	*
	* @param array $data data to be checked
	* @return object
	*/
	 public function check_skip($data)
	 {
		if ( ! empty($this->skip) AND is_array($this->skip))
			foreach ($this->skip as $skip)
				if (array_key_exists($skip, $data))
				{ 
					unset($data[$skip]);
				}
				
		return $data;
	 }
	
	/**
	*  Set where statement	
	*
	* @param string $where query where
	* @return object
	*/
	public function where($where = NULL)
	{
		if ( ! empty($where))
		{
			$this->where = $where;
		}

		return $this;
	}

	/**
	*  Set columns for select
	*
	* @param array $fields query select
	* @return object
	*/
	public function select($fields = array())
	{
		if (empty($fields)) 
			return $this;

		if (is_array($fields))
		{
			$this->select = $fields;
		}
		elseif(func_num_args() > 0)
		{
			$this->select = func_get_args();
		}

		return $this;
	} 

	/**
	*  Set limits for select	
	*
	* @param integer $limit query limit
	* @param integer $offset query offset	
	* @return object
	*/
	public function limit($limit, $offset = 0)
	{
		if (intval($limit) !== 0)
		{
			$this->limit = intval($limit);
			$this->offset = intval($offset);
		}
		return $this;
	}
	
	/**
	*  shortcut for easier count all records	
	*
	* @return integer
	*/
	public function count_all() 
	{
		//return $this->db->count_records($this->table_name);
		return db::build()->from($this->table_name)->count_records();
	}

	/**
	*  shortcut for easier count limited records	
	*
	* @param array $fields query where condition
	* @return integer
	*/
	public function count_where($fields = array()) 
	{
		//return $this->db->$type($fields)->count_records($this->table_name);
		return db::build()->from($this->table_name)->where($fields)->count_records();
	}

	/**
	*  Returns an associative array to use in dropdowns
	*
	* @param string $key returned array keys
	* @param string $display returned array values
	* @param string $order_by query ordering
	* @param array $where where conditions
	* @param string $direction query sorting				
	* @return array
	*/
	public function select_list($key, $display, $order_by = NULL,  $direction = 'ASC', $where = array())
	{
		(empty($order_by)) ? $order_by = $this->primary_key : NULL;
		
		$rows = array();

          $this->select = array($key, $display);
          $query = empty($where) ? $this->fetch_all($order_by, $direction) : $this->fetch_where($where, $order_by, $direction);

		/*if (empty($where))
		{
			//if no where statements, get all records 
			//$query = $this->db->select($key,$display)->orderby($order_by,$direction)->get($this->table_name)->result(TRUE);
			$query = db::select(array($key, $display))->order_by($order_by,$direction)->from($this->table_name)->execute($this->_db)->as_object(NULL,TRUE);
		}
		else
		{
			//get using where statement
			//$query = $this->db->select($key,$display)->$type($where)->orderby($order_by,$direction)->get($this->table_name)->result(TRUE);
			$query = db::select(array($key, $display))->where($where)->order_by($order_by,$direction)->from($this->table_name)->execute($this->_db)->as_object(NULL,TRUE);
		} */

		foreach ($query as $row)
		{
			//assign key - value for select
			$rows[$row->$key] = $row->$display;
		}

		return $rows;
	}
	
	/**
	*  check if data has been retrived from db and has a primary key value other than 0	
	*
	* @param string $field data key to be checked
	* @return boolean
	*/	
	public function loaded($field = NULL) 
	{
		(empty($field)) ? $field = $this->primary_key : NULL;
		return (intval($this->data[$field]) !== 0) ? TRUE : FALSE;
	}

	/**
	*  check if data has been modified	
	*
	* @return boolean
	*/
	public function diff() 
	{
		return ($this->data === $this->data_original) ? TRUE : FALSE;
	}
	
	/**
	*  clear values of $data and $data_original 	
	*
	* @return void
	*/
	public function clear_data()
	{
		array_fill_keys($this->data, '');
		array_fill_keys($this->data_original, '');
	}

	/**
	*  load table fields into $data	
	*
	* @return void
	*/
	public function load_columns() 
	{
		//only if table_name is set and there are no columns set
		if ( ! empty($this->table_name) AND (empty($this->data_original)) )
		{
			//only if auto_fields are enabled
			if (! IN_PRODUCTION AND $this->auto_fields)  
			{
				//load from DB
				$columns = $this->explain();
	
				$this->data = $columns;
				$this->data_original = $this->data;
			}
			else // rise an error? 
			{
				Kohana_Log::instance()->add('alert', 'Simple_Modeler, IN_PRODUCTION is TRUE and there is empty $data for table: '.$this->table_name);
			}
		}

		if ( ! empty($this->data) AND (empty($this->data_original)) )
			foreach ($this->data as $key => $value) 
			{
				$this->data_original[$key] = '';
			}
	}
	
	/**
	*  get table columns from db	
	*
	* @return array
	*/ 
	public function explain()
	{
		//get columns from database
		$columns = array_keys($this->_db->list_fields($this->table_name, TRUE));
		$data = array();

		//assign default empty values
		foreach ($columns as $column) 
		{ 
			$data[$column] = '';
		}
		return $data;
	}
	
	/**
	*  return current loaded data	
	*
	* @return array
	*/ 
	public function as_array()
	{
		return $this->data;
	}

	/**
	*  Magic get from $data	
	*
	* @param string $key key to be retrived
	* @return mixed
	*/	
	public function __get($key)
	{
		$key = $this->check_alias($key);

		if (array_key_exists($key, $this->data))
		{
			return $this->data[$key];
		}
		return NULL;
	}

	/**
	*  magic set to $data	
	*
	* @param string $key key to be modified
	* @param string $value value to be set
	* @return object
	*/
	public function __set($key, $value)
	{
		$key = $this->check_alias($key);

		if (array_key_exists($key, $this->data) AND (empty($this->data[$key]) OR $this->data[$key] !== $value))
		{
			return ($this->auto_trim) ? $this->data[$key] = trim($value) : $this->data[$key] = $value;
		}
		return NULL;
	}

	/**
	*  serialize only needed values (without DB connection)	
	*
	* @return array
	*/
	public function __sleep()
	{
		// Store only information about the object without db property
		return array_diff(array_keys(get_object_vars($this)), array('db'));
	}
	
	/**
	*  unserialize	
	*
	* @return void
	*/
	public function __wakeup()
	{
		// Initialize database
		$this->_db = Database::instance($this->_db);
	}

}
