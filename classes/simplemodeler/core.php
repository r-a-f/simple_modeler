<?php defined('SYSPATH') or die('No direct script access.');

/**
 * SimpleModeler - addon for Kohana Model class
*
* @package		SimpleModeler
* @author			thejw23
* @copyright		(c) 2009-2011 thejw23
* @license		http://www.opensource.org/licenses/isc-license.txt
* @version		3.0
* @last change
*
* @NOTICE			table columns should be different from class varibales/methods names
* @NOTICE			ie. having table column 'timestamp' or 'skip' may (and probably will) lead to problems
*
* modified version of Auto_Modeler by Jeremy Bush,
* class name changed to prevent conflicts while using original Auto_Modeler
*/

class SimpleModeler_Core extends Model_Database implements ArrayAccess
{
	const VERSION = '3.0c';

	/**
	 * @var  string  Table name
	 */
	protected $table_name = '';

	/**
	 * @var  string  Primary Key of a table
	 */
	protected $primary_key = 'id';

	/**
	 * @var  array  Record loaded from the database with load()
	 */
	protected $data_original = Array();

	/**
	 * @var  array  Record modified by set_fields()
	 */
	protected $data = Array();

	/**
	 * @var  array  Changes between loaded data ($data_original) and modified data ($data)
	 */
	protected $data_to_save = Array();

	/**
	 * @var  array  Aliases: 'form field name' => 'database field name'
	 */
	public $aliases = Array();

	/**
	 * @var  array  Field to be skipped durring the save()
	 */
	public $skip = Array ();

	/**
	 * @var  array  Fields updated with timestamp, on each save() after update.
	 */
	public $timestamp = Array('time_stamp');

	/**
	 * @var  array  Fields updated with timestamp, on each save() after insert.
	 */
	public $timestamp_created = Array('time_stamp_created');

	/**
	 * @var  string  Type of where statement.
	 */
	public $where = 'and_where';

	/**
	 * @var  array  Fields to be selected, for fetch_all() and fetch_where()
	 */
	public $select = array('*');

	/**
	 * @var  integer  Number of returned rows for fetch_all() and fetch_where()
	 */
	public $limit;

	/**
	 * @var  array  number of row to start from, for fetch_all() and fetch_where()
	 */
	public $offset = 0;

	/**
	 * @var  string  Type of the returned object
	 */
	public $result_object = 'stdClass'; //defaults, arrays: MYSQL_ASSOC objects: stdClass
	//to use model class as output: 'Model_'.inflector::singular(ucwords($my_model->table_name));

	/**
	 * @var  boolean  Weather or not read fields from the database or from the model class ($data)
	 */
	public $auto_fields = FALSE;

	protected $_state = SimpleModeler::STATE_NEW;

	const STATE_NEW = 'new';
	const STATE_LOADING = 'loading';
	const STATE_LOADED = 'loaded';
	const STATE_DELETED = 'deleted';

	// Lists available states for this model
	protected $_states = array(
		SimpleModeler::STATE_NEW,
		SimpleModeler::STATE_LOADING,
		SimpleModeler::STATE_LOADED,
		SimpleModeler::STATE_DELETED
	);


	/**
	 * Constructor, optionally loads the row with given primary key
	 *
	 *     // load the row with ID = 3
	 *     $row = new My_Model(3);
	 *
	 * @param   integer  value of primary key to be loaded
	 * @return  object
	 * @uses    load()
	 * @uses    load_columns()
	 */
	public function __construct($id = FALSE)
	{
		parent::__construct($this->_db);

		$this->result_object = get_class($this);

		if ($id != FALSE)
		{
			$this->load($id);
		}

		$this->load_columns();
	}

	/**
	 * Return a new instance of SimpleModeler model. Useful for one line method chaining.
	 * optionally loads the row with given primary key
	 *
	 *     // load the row with ID = 3
	 *     $row = SimpleModeler::factory('my_model',3);
	 *
	 * @param   string  model name
	 * @param   integer  value of primary key to be loaded
	 * @return  object
	 */
	public static function factory($model, $id = FALSE)
	{
		$model = empty($model) ? __CLASS__ : 'Model_'.ucwords($model);
		return new $model($id);
	}

	/**
	 * Return a static instance of SimpleModeler model. Useful for one line method chaining.
	 * optionally loads the row with given primary key
	 *
	 *     // load the row with ID = 3
	 *     $row = SimpleModeler::instance('my_model',3);
	 *
	 * @param   string  model name
	 * @param   integer  value of primary key to be loaded
	 * @return  object
	 */
	public static function instance($model = '', $id = FALSE)
	{
		static $instance;
		$model = empty($model) ? __CLASS__ : 'Model_'.ucwords($model);

		empty($instance) and $instance = new $model($id);

		if ( ! $instance instanceof $model)
			return  new $model($id);

		return $instance;
	}

	/**
	 * Generates user fiendly $data array with table columns
	 *
	 *	 //example use
	 *	 echo SimpleModeler::instance('my_model')->generate_data();
	 *
	 * @return  string
	 */
	public function generate_data()
	{
		$out = "";

		$columns = $this->explain();

		if (!empty($columns))
		{
			$out .= '<pre>table: '.$this->table_name."<br />";
			$out .= 'protected $data = array(<br />';
			foreach ($columns as $column => $type)
			{
				$out .= "\t\t'".$column."' => '',<br />";
			}
			$out = rtrim($out,',<br />');
			$out .= "<br />\t\t);</pre>";
		}

		return $out;
	}

	/**
	 * Return table name of the loaded model
	 *
	 *     $table = SimpleModeler::instance('my_model')->get_table_name();
	 *
	 * @return string
	 */
	public function get_table_name()
	{
		return $this->table_name;
	}

	/**
	 * set new values for a row, ready to be saved.
	 *
	 *     $my_model->set_data($data);
	 *
	 * @param array $data data passed to $data
	 * @return  object
	 */
	public function set_fields($data)
	{
		foreach ($data as $key => $value)
		{
			$key = $this->check_alias($key);

			if (array_key_exists($key, $this->data))
			{
				$this->data[$key] = $value;
			}
		}

		return $this;
	}

	/**
	 * save data into database
	 *
	 *     $my_model->save();
	 *     //or
	 *     $my_model->set_data($data)->save();
	 *
	 * @return  object
	 * @uses    check_timestamp()
	 * @uses    check_skip()
	 * @uses    loaded()
	 */
	public function save()
	{
		$this->data_to_save = array();
		$this->data_to_save = array_diff_assoc($this->data, $this->data_original);

		if (empty($this->data_to_save))
			return NULL;

		$this->check_timestamp($this->loaded());
		$this->check_skip();

		if (empty($this->data_to_save))
			return NULL;
			
		// Do an update
		//if ($this->loaded())
		if ($this->state() == SimpleModeler::STATE_LOADED)
		{
			return count(DB::update($this->table_name)->set($this->data_to_save)->where($this->primary_key, '=', $this->data[$this->primary_key])->execute());
		}
		else // Do an insert
		{
			$id = DB::insert($this->table_name)->columns(array_keys($this->data_to_save))->values(array_values($this->data_to_save))->execute();

			$this->state(SimpleModeler::STATE_LOADED);

			$this->data[$this->primary_key] = $id[0];
			$this->data_original = $this->data;

			return $id;
		}
		//return NULL;
		throw new SimpleModeler_Exception($status['string'], array(), $status['errors']);
	}

	/**
	 * reset settings
	 *
	 *     $my_model->reset();
	 *
	 * @return  object
	 */
	public function reset()
	{
		$this->where = 'and_where';
		$this->select = array('*');
		$this->limit = '';
		$this->offset = 0;
		$this->result_object = 'stdClass';
		return $this;
	}

	/**
	 * load single record based on primary key field value.
	 *
	 *     $my_model->load(3);
	 *     //or
	 *     $row = SimpleModeler::instance('My_Model')->load(3);
	 *
	* @param mixed $value column value
	* @param string $key column name
	* @return self
	 */
	public function load($value, $key = NULL)
	{
		$this->_state = SimpleModeler::STATE_LOADING;

		(empty($key)) ? $key = $this->primary_key : NULL;

		$data = DB::select_array($this->select)->from($this->table_name)->where($key, '=', $value)->execute();

		if (count($data) === 1 AND $data = $data->current())
		{
			$this->data_original = $data;
			$this->data = $this->data_original;
		}

		$this->process_load_state();

		return $this;
	}

	/**
	 * Processes the object state before a load() finishes
	 *
	 * @return null
	 */
	public function process_load_state()
	{
		if (!empty($this->{$this->primary_key}))
		{
			$this->_state = SimpleModeler::STATE_LOADED;
		}
		else
		{
			$this->_state = SimpleModeler::STATE_NEW;
		}
	}

	/**
	 * Gets/sets the object state
	 *
	 * @return string/$this when getting/setting
	 */
	public function state($state = NULL)
	{
		if ($state)
		{
			if ( ! in_array($state, $this->_states))
			{
				//return NULL;
				throw new SimpleModeler_Exception('Invalid state');
			}

			$this->_state = $state;

			return $this;
		}

		return $this->_state;
	}

	/**
	 * Returns single record without using $data
	 *
	 *     $my_model->fetch_row(3);
	 *     //or
	 *     $row = SimpleModeler::instance('My_Model')->fetch_row(3);
	 *
	* @param mixed $value column value
	* @param string $key column name
	* @return self
	 */
	public function fetch_row($value, $key = NULL)
	{
		(empty($key)) ? $key = $this->primary_key : NULL;

		$data = DB::select_array($this->select)->from($this->table_name)->where($key, '=', $value)->as_object($this->result_object)->execute();
		
		if (count($data) === 1 AND $data = $data->current())
		{
			return $data;
		}
	}


	/**
	 * delete current record loaded with load()
	 *
	 *     $my_model->delete();
	 *
	 * @return  mixed
	 */
	public function delete()
	{
		$result = false;
		if (SimpleModeler::STATE_LOADED)
		{
		//if (! empty($this->data[$this->primary_key]))
		//{
			$result = DB::delete($this->table_name)->where($this->primary_key, '=', $this->data[$this->primary_key])->execute();
			if ($result)
			{
				$this->clear_data();
				$this->_state = SimpleModeler::STATE_DELETED;
				return $result;
			}
		}
		throw new SimpleModeler_Exception('Cannot delete a non-saved model '.get_class($this).'!', array(), array());
	}
	
	public function delete_where($wheres = array())
	{
		$result = FALSE;
		$query = DB::delete($this->table_name); 
		$this->set_where($query, $wheres);
		$result = $query->execute();
		if ($result)
		{
			$this->clear_data();
			$this->_state = SimpleModeler::STATE_DELETED;
			return $result;
		}
	}

	/**
	 * Fetches all records from the table
	 *
	 *     $my_model->fetch_all();
	 *     //or
	 *     $row = SimpleModeler::instance('My_Model')->fetch_all('name','desc');
	 *
	 * @param string $order_by ordering
	 * @param string $direction sorting
	 * @return mixed
	 * @uses    limit()
	 */
	public function fetch_all($order_by = NULL, $direction = 'ASC')
	{
		(empty($order_by)) ? $order_by = $this->primary_key : NULL;

		$query =  DB::select_array($this->select)->order_by($order_by, $direction);

		if ( ! empty($this->limit))
		{
			$query->limit($this->limit)->offset($this->offset);
		}

		$result =  $query->from($this->table_name)->as_object($this->result_object)->execute();
		
		return $result;
	}

	/**
	 * Fetches some records from the table
	 *
	 *     $where = array('name','=','grealt');
	 *     $row = SimpleModeler::instance('My_Model')->fetch_where($where,'name','desc');
	 *
     * @param array $wheres where conditions
	 * @param array $order_by ordering
	 * @return mixed
	 * @uses    limit()
	 */
	public function fetch_where($wheres = array(), $order_by = NULL)
	{

		(empty($order_by)) ? $order_by = array($this->primary_key, 'ASC') : NULL;

		$type = $this->where;

		if (! is_array($wheres))
			return FALSE;

		$query = DB::select_array($this->select); //->order_by($order_by, $direction);
        $this->set_order($query, $order_by);

		if ( ! empty($this->limit))
		{
			$query->limit($this->limit)->offset($this->offset);
		}

		$this->set_where($query, $wheres);

		$result = $query->from($this->table_name)->as_object($this->result_object)->execute();
		
		return $result;
	}

	/**
	 * run custom query
	 *
	 *     $my_model->query('select * from clients','SELECT');
	 *
	 * @param string $sql query to run
	 * @param string $type query type
	 * @return mixed
	 */
	public function query($sql, $type = Database::SELECT)
	{
		return DB::query($type, $sql)->as_object($this->result_object)->execute();
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
	* @return nothing
	*/
	 public function check_timestamp($create = FALSE)
	 {
		//update timestamp fields with current datetime
		if ($create)
		{
			if ( ! empty($this->timestamp) AND is_array($this->timestamp))
				foreach ($this->timestamp as $field)
					if (array_key_exists($field, $this->data_original))
					{
						$this->data_to_save[$field] = date('Y-m-d H:i:s');
					}
		}
		else
		{
			if ( ! empty($this->timestamp_created) AND is_array($this->timestamp_created))
				foreach ($this->timestamp_created as $field)
					if (array_key_exists($field, $this->data_original))
					{
						$this->data_to_save[$field] = date('Y-m-d H:i:s');
					}
		}

	 }

	/**
	*  Checks if given key should be skipped
	*
	* @param array $data data to be checked
	* @return nothing
	*/
	 public function check_skip()
	 {
		if ( ! empty($this->skip) AND is_array($this->skip))
			foreach ($this->skip as $skip)
				if (array_key_exists($skip, $this->data_to_save))
				{
					unset($this->data_to_save[$skip]);
				}
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
	public function count_all($field = '*')
	{
		$data = DB::select(array('COUNT("'.$field.'")', 'total_rows'))->from($this->table_name)->execute();

		if (count($data) === 1 AND $data = $data->current())
		{
			return $data['total_rows'];
		}
	}

	public function set_where($query, $wheres)
	{
	 foreach ($wheres as $where)
		{
			if (is_array($where))
			{
				if (isset($where[3]))
				{
					$query->{$where[3]}($where[0], $where[1], $where[2]);
				}
				else
				{
					$query->{$this->where}($where[0], $where[1], $where[2]);
				}
			}
		}
	}

	public function set_order($query, $orders)
	{
		foreach ($orders as $order)
		{
			if (is_array($order))
			{
				$query->order_by($order[0], $order[1]);
			}
		}
	}

	/**
	*  shortcut for easier count limited records
	*
	* @param array $fields query where condition
	* @return integer
	*/
	public function count_where($wheres = array(), $field = '*')
	{
		$query = DB::select(array('COUNT("'.$field.'")', 'total_rows'));

		$this->set_where($query,$wheres);

		$data =  $query->from($this->table_name)->execute();

		if (count($data) === 1 AND $data = $data->current())
		{
			return $data['total_rows'];
		}

		return NULL;
	}

	/**
	*  Returns an associative array to use in dropdowns
	*
	* @param string $key returned array keys
	* @param string $display returned array values
	* @param string $order_by query ordering
	* @param string $direction query sorting
	* @param array $where where conditions
	* @return array
	*/
	public function select_list($key, $display, $order_by = NULL,  $direction = 'ASC', $where = array())
	{
		(empty($order_by)) ? $order_by = $this->primary_key : NULL;

		$rows = array();

          $this->select(array($key, $display));
		  
			if (empty($where))
			{
				$query = $this->fetch_all($order_by, $direction);
			}
			else
			{
				$query = $this->fetch_where($where, array(array($order_by, $direction)));
			}

		foreach ($query as $row)
		{
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
		$this->data = array_fill_keys($this->data, '');
		$this->data_original = array_fill_keys($this->data_original, '');
	}

	/**
	*  load table fields into $data.
	*
	* @return void
	*/
	public function load_columns()
	{
		if ( ! empty($this->table_name) AND (empty($this->data)) )
		{
			if (! IN_PRODUCTION AND $this->auto_fields)
			{
				$columns = $this->explain();

				$this->data = $columns;
				$this->data_original = $this->data;
			}
			else // rise an error?
			{
				Kohana::$log->add(Log::WARNING, 'Simple_Modeler, IN_PRODUCTION is TRUE and there is empty $data for table: '.$this->table_name);
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
		$columns = array_keys(Database::instance()->list_columns($this->table_name, TRUE));
		$columns = array_fill_keys($columns, '');
		return $columns;
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
	* @uses    check_alias()
	*/
	public function __get($key)
	{
		$key = $this->check_alias($key);
		if (array_key_exists($key, $this->data))
		{
			return $this->data[$key];
		}

		//throw new SimpleModeler_Exception('Field '.$key.' does not exist in '.get_class($this).'!', array(), '');
	}

	/**
	*  magic set for $data
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
			return $this->data[$key] = $value;
		}

		//throw new SimpleModeler_Exception('Field '.$key.' does not exist in '.get_class($this).'!', array(), '');
	}

	/**
	*  serialize only needed values (without DB connection)
	*
	* @return array
	*/
	public function __sleep()
	{
		return array_diff(array_keys(get_object_vars($this)), array('_db'));
	}

	/**
	*  unserialize
	*
	* @return void
	*/
	public function __wakeup()
	{
		// Initialize database
		$this->_db = Database::instance();
	}

	public function __isset($key)
	{
		$key = $this->check_alias($key);
		return isset($this->data[$key]);
	}
	
	// Array Access Interface
	public function offsetExists($key)
	{
		return array_key_exists($key, $this->data);
	}

	public function offsetSet($key, $value)
	{
		$this->__set($key, $value);
	}

	public function offsetGet($key)
	{
		return $this->$key;
	}

	public function offsetUnset($key)
	{
		$this->data[$key] = NULL;
	}

}

class SimpleModeler_Exception extends Kohana_Exception
{
	public $errors;

	public function __construct($title, array $message = NULL, $errors = '')
	{
		parent::__construct($title, $message);
		$this->errors = $errors;
	}

	public function __toString()
	{
		return $this->message;
	}
}