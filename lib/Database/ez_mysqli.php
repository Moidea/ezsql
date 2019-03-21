<?php
declare(strict_types=1);

namespace ezsql\Database;

use Exception;
use ezsql\ezsqlModel;
use ezsql\ConfigInterface;
use ezsql\DatabaseInterface;

final class ez_mysqli extends ezsqlModel implements DatabaseInterface
{
    /*
     * ezSQL error strings - mySQLi
     * @var array
     */
    private $ezsql_mysql_str = array
        (
            1 => 'Require $user and $password to connect to a database server',
            2 => 'Error establishing mySQL database connection. Correct user/password? Correct hostname? Database server running?',
            3 => 'Require $name to select a database',
            4 => 'mySQL database connection is not active',
            5 => 'Unexpected error while trying to select database'
        );

    /**
     * Show errors
     * @var boolean Default is true
     */
    protected $show_errors = true;
    
    protected $preparedValues = array();

    private static $isSecure = false;
    private static $secure = null;

    /**
     * Query result
     * @var mixed
     */
    private $_result;

    /**
     * Database configuration setting 
     * @var Configuration instance
     */
    private $database;

    public function __construct(ConfigInterface $settings = null) {
        if ( ! \class_exists ('ezsqlModel') ) {
            if ( ! \interface_exists('Psr\Container\ContainerInterface') )
                throw new Exception(\CONFIGURATION_REQUIRES);
        }

        if (empty($settings) || (!$settings instanceof ConfigInterface)) {
            throw new Exception(\MISSING_CONFIGURATION);
        }
        
        parent::__construct();
        $this->database = $settings;

        if (empty($GLOBALS['db_'.\MYSQLI]))
            $GLOBALS['db_'.\MYSQLI] = $this;
        \setInstance($this);
    } // __construct

    public function settings()
    {
        return $this->database;
    }

    /**
     * Short hand way to connect to mysql database server and select a mysql
     * database at the same time
     *
     * @param string $user The database user name
     * @param string $password The database users password
     * @param string $name The name of the database
     * @param string $host The host name or IP address of the database server.
     *                       Default is localhost
     * @param string $charset Encoding of the database
     * @return boolean
     */
    public function quick_connect(
        string $user = '', 
        string $password = '', 
        string $name = '', 
        string $host = 'localhost', 
        string $charset = '') 
    {
        $user = empty($user) ? $this->database->getUser() : $user;
        $password = empty($password) ? $this->database->getPassword() : $password;
        $name = empty($name) ? $this->database->getName() : $name;
        $host = ($host != 'localhost') ? $this->database->getHost() : $host;
        $charset = empty($charset) ? $this->database->getCharset() : $charset;

        if ( ! $this->connect($user, $password, $host, $charset) ) ;
        else if ( ! $this->select($name, $charset) ) ;

        return $this->_connected;
    } // quick_connect

    /**
     * Try to connect to mySQLi database server
     *
     * @param string $user The database user name
     * @param string $password The database users password
     * @param string $host The host name or IP address of the database server.
     *                       Default is localhost
     * @param string $charset The database charset
     *                      Default is empty string
     * @return boolean
     */
    public function connect(
        string $user = '', 
        string $password = '',
        string $host = 'localhost', 
        string $charset = '')
    {
        $this->_connected = false;

        $user = empty($user) ? $this->database->getUser() : $user;
        $password = empty($password) ? $this->database->getPassword() : $password;
        $host = ($host != 'localhost') ? $this->database->getHost() : $host;
        $charset = empty($charset) ? $this->database->getCharset() : $charset;

        // Must have a user and a password
        if ( empty($user ) ) {
            $this->register_error($this->ezsql_mysql_str[1] . ' in ' . __FILE__ . ' on line ' . __LINE__);
            $this->show_errors ? \trigger_error($this->ezsql_mysql_str[1], \E_USER_WARNING) : null;
        } else if ( ! $this->dbh = \mysqli_connect($host, $user, $password, $this->database->getName()) ) {
            // Try to establish the server database handle
            $this->register_error($this->ezsql_mysql_str[2] . ' in ' . __FILE__ . ' on line ' . __LINE__);
            $this->show_errors ? \trigger_error($this->ezsql_mysql_str[2], \E_USER_WARNING) : null;
        } else {
            \mysqli_set_charset($this->dbh, $charset);
            $this->_connected = true;
        }

        return $this->_connected;
    } // connect

    /**
     * Try to select a mySQL database
     *
     * @param string $name The name of the database
     * @param string $charset Encoding of the database
     * @return boolean
     */
    public function select($name = '', $charset = '') 
    {
        $this->_connected = false;
        $name = empty($name) ? $this->database->getName() : $name;
        $charset = empty($charset) ? $this->database->getCharset() : $charset;
        if ( ! $name ) {
            // Must have a database name
            $this->register_error($this->ezsql_mysql_str[3] . ' in ' . __FILE__ . ' on line ' . __LINE__);
            $this->show_errors ? \trigger_error($this->ezsql_mysql_str[3], \E_USER_WARNING) : null;
        } elseif ( ! $this->dbh ) {
            // Must have an active database connection
            $this->register_error($this->ezsql_mysql_str[4] . ' in ' . __FILE__ . ' on line ' . __LINE__);
            $this->show_errors ? \trigger_error($this->ezsql_mysql_str[4], \E_USER_WARNING) : null;
        } elseif ( !\mysqli_select_db($this->dbh, $name) ) {
            // Try to connect to the database
            // Try to get error supplied by mysql if not use our own
            if ( !$str = \mysqli_error($this->dbh)) {
                $str = $this->ezsql_mysql_str[5];
            }

            $this->register_error($str . ' in ' .__FILE__ . ' on line ' . __LINE__);
            $this->show_errors ? \trigger_error($str, \E_USER_WARNING) : null;
        } else {
            $this->database->setName($name);
            if ( $charset == '') {
                $charset = $this->database->getCharset();
            }

            if ( $charset != '' ) {
                $encoding = \strtolower(\str_replace('-', '', $charset));
                $charset = array();
                $recordSet = \mysqli_query($this->dbh, 'SHOW CHARACTER SET');
                while ( $row = \mysqli_fetch_array($recordSet, \MYSQLI_ASSOC) ) {
                        $charset[] = $row['Charset'];
                }

                if ( \in_array($charset, $charset) ) {
                    \mysqli_query($this->dbh, 'SET NAMES \'' . $encoding . '\'');
                }
            }
            $this->_connected = true;
        }

        return $this->_connected;
    } // select

    /**
     * Format a mySQL string correctly for safe mySQL insert
     * (no matter if magic quotes are on or not)
     *
     * @param string $str
     * @return string
     */
    public function escape(string $str) 
    {
        return \mysqli_real_escape_string($this->dbh, \stripslashes($str));
    } // escape

    /**
     * Return mySQLi specific system date syntax
     * i.e. Oracle: SYSDATE Mysql: NOW()
     *
     * @return string
     */
    public function sysDate() {
        return 'NOW()';
    }
    
    /**
     * Helper fetches rows from a prepared result set 
     * @param mysqli_stmt $stmt 
     * @param string $query
     * @return bool|mysqli_result
     */
    private function fetch_prepared_result(&$stmt, $query) 
    {
        if($stmt instanceof mysqli_stmt) {
            $stmt->store_result();       
            $variables = array();
            $is_insert = false;
            if ( \preg_match("/^(insert|delete|update|replace)\s+/i", $query) ) {
                $this->_affectedRows = \mysqli_stmt_affected_rows($stmt);

                // Take note of the insert_id
                if ( \preg_match("/^(insert|replace)\s+/i", $query) ){
                    $this->insert_id = $stmt->insert_id;
                }
            } else {
                $this->_affectedRows = $stmt->num_rows;
                $meta = $stmt->result_metadata();
       
                // Take note of column info
                while($field = $meta->fetch_field())
                    $variables[] = &$this->col_info[$field->name]; // pass by reference
                
                // Binds variables to a prepared statement for result storage
                \call_user_func_array([$stmt, 'bind_result'], $variables);
       
                $i = 0;
                // Store Query Results
                while($stmt->fetch()) {
                    // Store results as an objects within main array
                    foreach($this->col_info as $key => $value)
                        $this->last_result[$i] = (object) array( $key => $value );
                    $i++;           
                }
            }       
            
            // If there is an error then take note of it..
            if ( $str = $stmt->error ) {
                $is_insert = true;
                $this->register_error($str);
                $this->show_errors ? \trigger_error($str, \E_USER_WARNING) : null;
                
                // If debug ALL queries
                $this->trace || $this->debug_all ? $this->debug() : null ;
                return false;
            }
               
            // Return number of rows affected
            $return_val = $this->_affectedRows;
            
            // disk caching of queries
            $this->store_cache($query, $is_insert);

            // If debug ALL queries
            $this->trace || $this->debug_all ? $this->debug() : null ;
            
            return $return_val;
        }

        return false;
    }	

	/**
     * Creates a prepared query, binds the given parameters and returns the result of the executed
     * {@link mysqli_stmt}.
     * @param string $query
     * @param array $args
     * @return bool|mysqli_result
     */
    public function query_prepared($query, array $args)
    {
        $stmt   = $this->dbh->prepare($query);
        $params = [];
        $types  = \array_reduce($args,
            function ($string, &$arg) use (&$params) {
                $params[] = &$arg;
                if (\is_float($arg))
                    $string .= 'd';
                elseif (\is_integer($arg))
                    $string .= 'i';
                elseif (\is_string($arg))
                    $string .= 's';
                else    
                    $string .= 'b';
                return $string;
            }, ''
        );
        
        \array_unshift($params, $types);
            
        \call_user_func_array([$stmt, 'bind_param'], $params);
        
        $result = ($stmt->execute()) ? $this->fetch_prepared_result($stmt, $query) : false;  
        
        // free and closes a prepared statement
        $stmt->free_result();
        $stmt->close();

        return $result;
    }
    
    /**
     * Perform mySQL query and try to determine result value
     *
     * @param string $query
     * @return boolean
     */
    public function query($query, $use_prepare = false)
    {
        $param = [];
        if ($use_prepare)
            $param = $this->prepareValues();
        
		// check for ezQuery placeholder tag and replace tags with proper prepare tag
		$query = \str_replace(\_TAG, '?', $query);
		
        // Initialize return
        $return_val = 0;

        // Flush cached values..
        $this->flush();

        // For reg expressions
        $query = \trim($query);

        // Log how the function was called
        $this->log_query("\$db->query(\"$query\")");

        // Keep track of the last query for debug..
        $this->last_query = $query;

        // Count how many queries there have been
        $this->num_queries++;

        // Use core file cache function
        if ( $cache = $this->get_cache($query) ) {
            return $cache;
        }

        // If there is no existing database connection then try to connect
        if ( ! isset($this->dbh) || ! $this->dbh ) {
            $this->connect($this->database->getUser(), $this->database->getPassword(), $this->database->getHost());
            $this->select($this->database->getName());
        }

        // Perform the query via std mysql_query function..
		if (!empty($param) && \is_array($param) && ($this->isPrepareActive()))		
			return $this->query_prepared($query, $param);
		else 
			$this->_result = \mysqli_query($this->dbh, $query);

        // If there is an error then take note of it..
        if ( $str = \mysqli_error($this->dbh) ) {
            $is_insert = true;
            $this->register_error($str);
            $this->show_errors ? \trigger_error($str, \E_USER_WARNING) : null;
            
            // If debug ALL queries
            $this->trace || $this->debug_all ? $this->debug() : null ;
            return false;
        }

        // Query was an insert, delete, update, replace
        $is_insert = false;
        if ( \preg_match("/^(insert|delete|update|replace)\s+/i", $query) ) {
            $this->_affectedRows = \mysqli_affected_rows($this->dbh);

            // Take note of the insert_id
            if ( \preg_match("/^(insert|replace)\s+/i", $query) ) {
                $this->insert_id = \mysqli_insert_id($this->dbh);
            }

            // Return number of rows affected
            $return_val = $this->_affectedRows;
        } else {
            if ( !\is_numeric($this->_result) && !\is_bool($this->_result)) {
                // Query was a select

                // Take note of column info
                $i = 0;
                while ($i < \mysqli_num_fields($this->_result)) {
                    $this->col_info[$i] = \mysqli_fetch_field($this->_result);
                    $i++;
                }

                // Store Query Results
                $num_rows = 0;
                while ( $row = \mysqli_fetch_object($this->_result) ) {
                    // Store results as an objects within main array
                    $this->last_result[$num_rows] = $row;
                    $num_rows++;
                }

                \mysqli_free_result($this->_result);

                // Log number of rows the query returned
                $this->num_rows = $num_rows;

                // Return number of rows selected
                $return_val = $this->num_rows;
            }
        }

        // disk caching of queries
        $this->store_cache($query, $is_insert);

        // If debug ALL queries
        $this->trace || $this->debug_all ? $this->debug() : null ;

        return $return_val;
    } // query
	
    /**
     * Close the database connection
     */
    public function disconnect() 
    {
        if ( $this->dbh ) {
            \mysqli_close($this->dbh);
            $this->_connected = false;
        }

        $this->_connected = false;
    } // function

    /**
     * Returns the current database server host
     *
     * @return string
     */
    public function getDBHost() 
    {
        return $this->database->getHost();
    } // getDBHost

    /**
     * Returns the current connection charset
     *
     * @return string
     */
    public function getCharset() 
    {
        return $this->database->getCharset();
    } // getCharset

    /**
     * Returns the last inserted auto-increment
     *
     * @return int
     */
    public function getInsertId() 
    {
        return \mysqli_insert_id($this->dbh);
    } // getInsertId

} // ez_mysqli