<?php

/**
 * @package Grid
 * @version 0.8.2
 * @author Greg Tyler
 */

class Grid {

    public $version = "0.8.2";
    public $_connected = false;

    public $allFields = array();
    public $alii = array();
    
    private $_table = "";
    private $_limit = 0;
    private $_offset = 0;
    private $_where = array();
    private $_whereFields = array();
    private $_by = array();
    private $_group = array();
    private $_assoc = true;
    
    public $_lastQuery = '';
    public $_queryCount = 0;
    public $_queries = array();

    public $_showErrors = true;
    public $_lastError = '';
    public $_errors = array();

    private $config = array();
    private $_con;
    private $_schema = array();
    public $database = "";
    
   /**
    * constructor; sets constants for langError
    * 
    */
    public function __construct() {
        $errs = array(
            'GRID_UNEXPECTED_ERROR' => "An unexpected error occurred.",
            'GRID_NO_DATABASE'      => "Database not specified.",
            'GRID_CONNECTION_ERROR' => "Could not connect to server.",
            'GRID_NO_TABLE'         => "Table not specified.",
            'GRID_LOST_TABLE'       => "The specified table does not exist.",
            'GRID_NO_WHERE'         => "No WHERE clause was supplied.",
            'GRID_BAD_QUERY'        => "The query could not be processed (GRID_BAD_QUERY).",
            'GRID_EROTEME_MISMATCH' => "The number of arguments did not match the number of question marks.",
        );
        foreach( $errs as $comm => $message ) {
            if(!defined($comm)) define($comm, $message);
        }
    }
    
   /**
    * Connect to the database
    * 
    * @param string $server the server where the database is hosted
    * @param string $username username to access database
    * @param string $password password to access database
    * @param string $database the name of the database to connect to
    * @return boolean whether a connection was successful
    */
    public function connect($server = 'localhost', $username = '', $password = '', $database = '') {
        $this->_con = mysqli_init();
        if ($ret = @mysqli_real_connect($this->_con, $server, $username, $password)) {
            
            $this->config = array(
                'username' => $username,
                'password' => $password,
                'server'   => $server
            );

            if ($database == "") {
                $this->error( GRID_NO_DATABASE, __FILE__, __LINE__ );
            } else if (!@mysqli_select_db($this->_con, $database)) {
                $this->error( GRID_CONNECTION_ERROR, __FILE__, __LINE__ );
            } else {
                $this->database = $database;
            }
            $this->_connected = true;
        } else {
            $this->error( GRID_CONNECTION_ERROR, __FILE__, __LINE__ );
        }
        return $ret;
    }

   /**
    * Add an error to the log and output it if allowed
    * 
    * @param mixed $error the error text, or the id of the error in _langError
    * @param string $file the file where the error occured
    * @param int $line the line on which the error occured
    */
    public function error( $error, $file=false, $line=false ) {
        if( is_numeric($error) ) {
            $error = $this->_langError[$error];
        } else if( is_string($error) ) {
            $error = $error;
        } else {
            $error = $this->_langError[GRID_UNEXPECTED_ERROR];
        }

        $this->_lastError = $error;
        $this->_capturedErrors[] = array(
                'error'     => $error,
                'file'      => $file,
                'line'      => $line,
                'lastQuery' => $this->_lastQuery
            );

        if ( $this->_showErrors ) {
            echo "<strong>Grid error</strong> " . $error;
            if( $file!==false && $line!==false ) {
                echo " (line " . $line . ", \"" . $file . "\")";
            }
            echo "<br />";
        }
    }

   /**
    * A load of simple getters and setters
    */
    public function LastError() { return $this->_lastError; }
    public function ErrorMsg() { return $this->lastError(); }
    public function isConnected() { return $this->_connected; }
    public function affectedRows() { return @mysqli_affected_rows($this->_con); }
    public function insertID() { return mysqli_insert_id($this->_con); }
    public function ShowErrors( $show = true ) { $this->_showErrors = $show; }
    public function HideErrors() { $this->ShowErrors( false ); }
    
   /*
    * Install the schema, effectively
    */
    public function schema( $arr ) {
        foreach( $arr as $table => $dets ) {
            $this->allFields[$table] = array();
            $this->alii[$table] = array();
            if( $dets['fields'] ) foreach( explode( ",", $dets['fields'] ) as $field ) {
                $this->allFields[$table][] = array( 'table'=>$table, 'field'=>$field );
            }
            if( $dets['alias'] ) foreach( $dets['alias'] as $alias => $field ) {
                $this->alii[$table][$field] = $alias;
            }
        }
        $this->_schema = $arr;
    }
    
   /**
    * Flush the Grid of switches
    */
    public function flush() {
        $this->_where = array();
        $this->_whereFields = array();
        $this->_by = array();
        $this->_group = array();
        $this->_table = '';
        $this->_limit = 0;
        $this->_offset = 0;
        $this->_assoc = true;
    }
    
   /**
    * Wrap a single bit of data to go into a query
    */
    public function wrap( $bit, $table = false ) {
        $wrap = $table?"`":"'";
        if( is_null( $bit ) ) {
            return "NULL";
        } else if( is_bool( $bit ) ) {
            return $bit?'1':'0';
        } else if( is_numeric( $bit ) && is_int( $bit ) ) {
            return (int)$bit;
        } else if( is_array( $bit ) ) {
            foreach( $bit as $b ) {
                $w[] = $this->wrap( $b );
            }
            return "(" . implode(",", $w) . ")";
        } else {
            return $wrap . $this->escape_string( $bit ) . $wrap;
        }
    }
    
   /**
    * Wrapper for mysqli_real_escape_string for outside callers
    */
    public function escape_string( $str ) {
        return mysqli_real_escape_string( $this->_con, $str );
    }
    
   /**
    * Given an alias, get the real field name
    */
    public function alias( $field, $table=null ) {
        if( is_null($table) )  { $table = $this->_table; }
        $a = $this->_schema[$table]['alias'][$field];
        return $a?$a:$field;
    }
    
   /**
    * Run a function
    */
    public function runFunction( $func ) {
        if( function_exists( $func ) ) {
            return call_user_func( $func );
        }
    }
    
   /**
    * Given an alias, get the real field name
    */
    public function debug() {
        $out = '<table style="max-width:600px;border:1px solid #AAA;">
            <tr><th colspan="2" style="font-size:24px;text-align:center;">Stats</th></tr>
            <tr><th style="width:150px;">Database</th><td>' . $this->database . '</td></tr>
            <tr><th>Queries</th><td>' . count($this->_queries) . '</td></tr>
            <tr><th>Tables</th><td>' . count($this->_schema) . '</td></tr>
            <tr><th colspan="2" style="font-size:24px;text-align:center;">Queries</th></tr>';
        
        foreach( $this->_queries as $q ) {
            $out .= '
            <tr><td colspan="2">' . $q . '</td></tr>';
        }
        
        $out .= '
            <tr><th colspan="2" style="font-size:24px;text-align:center;">Tables</th></tr>';
        
        foreach( $this->_schema as $table=>$data ) {
            $aliases = "";$assoc = "";$children = "";
            if($data['alias']) foreach( $data['alias'] as $a=>$f ) {
                $aliases .= "`" . $a . "` is an alias for `" . $f . "`<br />";
            }
            if($data['assoc']) foreach( $data['assoc'] as $t=>$fs ) {
                $assoc .= $t . " on `$table`.`${fs[0]}` = `$t`.`${fs[1]}`<br />";
            }
            if($data['children']) foreach( $data['children'] as $t=>$fs ) {
                $assoc .= $t . " on `$table`.`${fs[0]}` = `$t`.`${fs[1]}`<br />";
            }
            $out .= '
            <tr><th colspan="2" style="text-align:center;">' . $table . '</th></tr>
            <tr><th>Fields</th><td>' . $data['fields'] . '</td></tr>
            <tr><th>Aliases</th><td>' . ($aliases?$aliases:"None") . '</td></tr>
            <tr><th>Associations</th><td>' . ($assoc?$assoc:"None") . '</td></tr>
            <tr><th>Children</th><td>' . ($children?$children:"None") . '</td></tr>';
        }
            
        $out .= '</table>';
        $out .= '</div>';
        echo $out;
    }
    
   /**
    * Work out which fields to get. Returns the string for SELECT ? FROM
    */
    public function fields( $rfields, $mode = "select" ) {
        // Get all the possible fields
        $allFields = $this->allFields[$this->_table];
        $alii[$this->_table] = $this->alii[$this->_table];
        if( $this->_schema[$this->_table]['assoc'] ) {
            if( is_array( $this->_assoc ) ) {
                $assoc = $this->_assoc;
            } elseif( $this->_assoc!==FALSE ) {
                $assoc = array_keys( $this->_schema[$this->_table]['assoc'] );
            }
        }
        
        if( $assoc ) {
            foreach( $assoc as $a ) {
                $allFields = array_merge( $allFields, $this->allFields[$a] );
                $alii[$a] = $this->alii[$a];
            }
        }
        
        // Is this an update query?
        $pairs = $mode==="update";
        if( $pairs ) {
            $values = $rfields;
            $rfields = array_keys( $rfields );
        }
        
        // Figure out which fields we want
        if( $rfields ) {
            if( count($rfields)===1 ) {
                if( is_array( $rfields[0] ) ) {
                    $rfields = $rfields[0];
                } else if( is_string( $rfields[0] ) && strpos($rfields[0],',')!==false ) {
                    $rfields = explode( ',', $rfields[0] );
                }
            }
            if( !is_array( $rfields ) ) {
                $rfields = array( $rfields );
            }
            
            $fields = array();
            foreach( $rfields as $field ) {
                // From what we're given, work out what's being searched for
                if( preg_match('/^([a-z_]+)\(([a-z]+)\)$/i', $field, $match) ) {
                    $aggr = $match[1];
                    $field = $match[2];
                } else {
                    $aggr = '';
                }
                foreach( $allFields as $search ) {
                    if( $alii[($search['table'])][($search['field'])] ) {
                        $alias = $alii[($search['table'])][($search['field'])];
                    }
                    if( $search['field'] == $field || $alias == $field ) {
                        if( $mode === 'lookup' ) {
                            $r = "`" . $search['table'] . "`.`" . $search['field'] . "`";
                            if( $aggr!=='' ) {
                                $r = strtoupper($aggr) . '(' . $r . ')';
                            }
                            return $r;
                        }
                        $search['orig'] = $field;
                        $search['aggr'] = $aggr;
                        $fields[] = $search;
                        break;
                    }
                }
            }
        }
        
        if( $mode === 'lookup' ) {
            return false;
        }
        
        // No luck? Just get everything!
        if( !$fields ) {
            $fields = $allFields;
        }
        
        $taken = array();
        $grab = array();
        // Name them
        foreach( $fields as $k=>$unit ) {
            $unit['lookup'] = "`" . $unit['table'] . "`.`" . $unit['field'] . "`";
            $unit['alias'][] = $unit['table'] . "." . $unit['field'];
            if( !in_array( $unit['field'], $taken ) ) {
                $taken[] = $unit['field'];
                $unit['alias'][] = $unit['field'];
            }
            // Add any unclaimed aliases
            if( $alii[($unit['table'])][($unit['field'])] ) {
                $alias = $alii[($unit['table'])][($unit['field'])];
                $unit['alias'][] = $unit['table'] . "." . $alias;
                if( !in_array( $alias, $taken ) ) {
                    $taken[] = $alias;
                    $unit['alias'][] = $alias;
                }
            }
            
            // Convert to SQL strings
            if( $pairs ) {
                $grab[] = $unit['lookup'] . "=" . $this->wrap( $values[($unit['orig'])] );
            } elseif( $unit['aggr'] ) {
                $grab[] = $unit['aggr'] . '(' . $unit['lookup'] . ') AS `' . strtolower($unit['aggr']) . '`';
            } else {
                foreach( $unit['alias'] as $a ) {
                    $grab[] = $unit['lookup'] . " AS " . "`" . $a . "`";
                }
            }
            
            $fields[$k] = $unit;
        }
        
        if( $mode==="array" ) {
            return $fields;
        }
        return implode( ", ", $grab );
    }
    
   /**
    * Straight out perform a raw SQL query; no funny business
    * 
    * @param string $sql the SQL to be executed
    * @param array $fields an array of values that were introduced in execute()
    * @return Boolean response if suitable, else a 2d array of results
    */
    private function _execute( $sql ) {
        $sql .= "; ";
        @mysqli_multi_query( $this->_con, $sql );
        $this->_lastQuery = $sql;
        $result = mysqli_store_result( $this->_con );

        $this->flush();
        
        $error = mysqli_error( $this->_con );
        if( $error ) {
            $this->error( "Mysqli: " . $error, __FILE__, __LINE__ );
        }

        if( is_bool( $result ) ) {
            return (bool)$result;
        }
        
        $this->_lastQuery = $sql;
        $this->_queries[] = $sql;
        $this->_queryCount++;

        $a = array();
        while( $r = mysqli_fetch_array( $result, MYSQLI_ASSOC ) ) {
            $a[] = $r;
        }
        
        return $a;
    }
   
   /**
    * Build a query from the conditions that've been passed so far
    */
    private function build( $type, $fields = array() ) {
        if( !$this->_table ) {$this->error( GRID_NO_TABLE, __FILE__, __LINE__ );return "";}
        if( !$type ) {$this->error( "This shouldn't be possible", __FILE__, __LINE__ );return "";}
        
        if( $this->_schema[$this->_table]['assoc'] && $this->_assoc!==FALSE ) {
            $joins = "";
            
            foreach( $this->_schema[$this->_table]['assoc'] as $table=>$join ) {
                if( is_array($this->_assoc) && !in_array( $table, $this->_assoc ) ) {
                    continue;
                }
                $joins .= " LEFT JOIN `" . $table . "` ON `" . $this->_table . "`.`" . $join[0] . "` = `" . $table . "`.`" . $join[1] . "` ";
            }
        }
        
        if( $type==="select" ) {
            $fields = $this->fields( $fields );
            
            // Modifiers
            $where = $this->buildWhere();
            if( $where ) { $where = " WHERE " . $where; }
            if( $this->_group ) { $group = " GROUP BY " . implode( ",", $this->_group ); }
            if( $this->_by ) { $order = " ORDER BY " . implode( ",", $this->_by ); }
            if( $this->_limit ) {
                if( $this->_offset ) $this->_limit = $this->offset . ',' . $this->_limit;
                $limit = " LIMIT " . $this->_limit;
            } else {
                if( $this->_offset ) {
                    $limit = " LIMIT " . $this->_offset . ",18446744073709551615";
                }
            }
            
            $sql = "SELECT " . $fields . " FROM `" . $this->_table . "`" . $joins . $where . $group . $order . $limit;
        } else if( $type==="count" ) {
            $where = $this->buildWhere();
            if( $where ) { $where = " WHERE " . $where; }
            if( $this->_group ) { $group = " GROUP BY " . implode( ",", $this->_group ); }
            $sql = "SELECT COUNT(*) AS `count` FROM `" . $this->_table . "`" . $joins . $where . $group;
        } else if( $type==="update" ) {
            $fields = $this->fields( $fields, "update" );
            $where = $this->buildWhere();
            if(!$where) return;
            $sql = "UPDATE `" . $this->_table . "` " . $joins . " SET " . $fields . " WHERE " . $where;
        } else if( $type==="delete" ) {
            $where = $this->buildWhere();
            if(!$where) return;
            $sql = "DELETE FROM `" . $this->_table . "` " . $children . " WHERE " . $where;
        } else if( $type==="scrub" ) {
            $where = $this->buildWhere();
            if(!$where) return;
            $sql = "DELETE FROM `" . $this->_table . "` " . $children . " WHERE " . $where;
            if( $this->_schema[$this->_table]['children'] ) {
                foreach( $this->_schema[$this->_table]['children'] as $table => $join ) {
                    $f = $this->_execute( "SELECT `" . $join[0] . "` FROM `" . $this->_table . "` WHERE " . $where );
                    $row = $f[0];
                    $sql .= "; DELETE FROM `" . $table . "` WHERE `" .$join[1] . "`=" . $this->wrap( $row[($join[0])] );
                }
            }
        } else if( $type==="insert" ) {
            foreach( $fields as $k=>$v ) {
                $keys[] = "`" . $this->alias( $k ) . "`";
                $values[] = $this->wrap( $v );
            }
            // Insert default values
            if( $this->_schema[($this->_table)]['defaults'] ) {
                foreach( $this->_schema[($this->_table)]['defaults'] as $f=>$d ) {
                    if( !in_array( $f, $fields ) ) {
                        $keys[] = "`" . $f . "`";
                        $values[] = $this->runFunction( $d );
                    }
                }
            }
            $sql = "INSERT INTO `" . $this->_table . "` (" . implode(",",$keys) . ") VALUES (" . implode(",",$values) . ") ";
        } else {
            $this->error( GRID_BAD_QUERY, __file__, __line__ );
            return;
        }
        
        return $sql;
    }
    
    
   /**
    * Make the WHERE statements work for me!
    */
    public function buildWhere() {
        $fields = $this->_whereFields;
        $i = 0;
        
        // Don't waste our time
        if( !$this->_where ) return;
        
        $statement = '(' . implode( ") AND (", $this->_where ) . ')';
        
        $break = explode( "?", $statement);
        if( count($break) !== count($fields)+1 ) {
            $this->error( GRID_EROTEME_MISMATCH, __FILE__, __LINE__ );
            return false;
        }
        
        $splitters = array( "NOT", "OR", "||", "XOR", "AND", "&&", "(", ")" );
        
        $split = preg_split( "/(\sNOT\s|\sOR\s|\|\||\sXOR\s|\sAND\s|&&|\(|\))/is", $statement, null, PREG_SPLIT_DELIM_CAPTURE );
        foreach( $split as $k=>$s ) {
            $s = trim($s);
            if( !$s ) unset( $split[$k] );
            if( in_array( $s, $splitters ) ) continue;
            $p = preg_match( "/([.a-z0-9_-]+|\?)(?:[ ]*)([<=>!]+|LIKE|IN)(?:[ ]*)(.+)/is", $s, $m );
            if( $s==="?" ) {
                $s = $fields[$i++];
                $split[$k] = $s;
            } else if( $p ) {
                if( $m[1] === "?" ) {
                    $m[1] = $fields[$i++];
                }
                
                if( strpos($m[1],".")!==FALSE ) {
                    $m[1] = $m[1] = "`" . str_replace(".", "`.`", $m[1]) . "`";
                } else {
                    $m[1] = $this->fields( $this->alias( $m[1] ), 'lookup' );
                }
                
                $mlk = $this->fields( $this->alias( $m[3] ), 'lookup' );

                if( $m[3] === "?" ) {
                    $m[3] = $this->wrap( $fields[$i++] );
                } elseif( in_array( $m[3], array( '%?', '?%', '%?%' ) ) ) {
                    $m[3] = $this->wrap( str_replace( '?', $fields[$i++], $m[3] ) );
                } elseif( $mlk ) {
                    $m[3] = $mlk;
                } else {
                    $m[3] = $this->wrap( $m[3] );
                }
                $s = $m[1] . " " . $m[2] . " " . $m[3];
                $split[$k] = $s;
            }
        }
        
        $statement = implode( " ", $split );
        return $statement;
    }
    
   /**
    * What table should we use?
    */
    public function with( $table ) {
        // tablename*
        if( substr($table,-1)==="*" ) {
            $table = substr( $table , 0, -1 );
            $this->noRel();
        }
        
        // tablename|other,tables
        $p = strpos( $table, '|' );
        if( $p!==FALSE ) {
            $this->_assoc = explode( ',', substr( $table, $p+1 ) );
            $table = substr( $table, 0, $p );
        }
        
        // Set it
        if( !$this->allFields[$table] ) {
            $this->error(GRID_LOST_TABLE,__FILE__,__LINE__);
            $this->flush();
            return $this;
        }
        $this->_table = $table;
        return $this;
    }
    public function from( $table ) { return $this->with($table); }
    
   /**
    * Add a where statement. Does some nice parsing stuff.
    */
    public function where( $statement ) {
        $fields = func_get_args();
        array_shift( $fields );        

        $this->_where[] = $statement;
        $this->_whereFields = array_merge( $this->_whereFields,  $fields );
        
        return $this;
    }
    
   /**
    * Order by what?
    */
    public function by( $field, $dir='ASC' ) {
        $field = $this->fields( $field, 'lookup' );
        $this->_by[] = $field . " " . strtoupper( $dir );
        return $this;
    }
    
   /**
    * Group by what?
    */
    public function group( $field ) {
        $field = $this->fields( $field, 'lookup' );
        $this->_group[] = $field;
        return $this;
    }
    
   /**
    * Limit X
    */
    public function limit( $lim, $offset = 0 ) {
        $this->_limit = (int)$lim;
        if( $offset ) {
            $this->_offset = (int)$offset;
        }
        return $this;
    }
    
    public function offset( $offset ) {
        $this->_offset = (int)$offset;
        return $this;
    }
    
   /**
    * Don't load associated tables
    */
    public function noRel() {
        $this->_assoc = false;
        return $this;
    }
    
    
    // TERMINAL FUNCTIONS
    
   /*
    * Get the SELECT SQL
    */
    public function getSQL() {
        $args = func_get_args();
        return $this->Build( "select", $args );
    }
    
   /**
    * Get a single entry (the first one)
    */
    public function getOne() {
        $args = func_get_args();
        $q = $this->_execute( call_user_func_array( array($this,"getSQL"), $args ) );
        return $q[0];
    }
    
   /**
    * Get every matching entry
    */
    public function getAll() {
        $args = func_get_args();
        return $this->_execute( call_user_func_array( array($this,"getSQL"), $args ) );
    }
   
   /**
    * Count how many matching entries there are
    */
    public function count() {
        $sql = $this->build( "count" );
        $out = $this->_execute( $sql );
        return (int)$out[0]['count'];
    }
    
   /**
    * Update the matching entries with some key/value pairs
    */
    public function update( $table, $pairs=null ) {
        if( !$this->_where ) {
            $this->error( GRID_NO_WHERE, __file__, __line__ );
            return;
        }
        
        if( is_string( $table ) ) {
            $this->_table = $table;
            $fields = $pairs;
        } else {
            $fields = $table;
        }
        
        $sql = $this->Build( "update", $fields );
        $this->_execute( $sql );
        return $this->affectedRows();
    }
   
   /**
    * Delete the matching entries
    */
    public function delete( $table=null, $scrub=false ) {
        if( !$this->_where ) {
            $this->error( GRID_NO_WHERE, __file__, __line__ );
            return;
        }
        if( $table ) {
            $this->_table = $table;
        }
        $sql = $this->Build( $scrub?"scrub":"delete" );
        $this->_execute( $sql );
        return $this->affectedRows();
    }
    
   /**
    * Delete the matching entries
    */
    public function scrub( $table=null ) {
        $this->delete( $table, true );
    }
    
   /**
    * Insert some data into the selected table
    */
    public function insert( $table, $pairs=null ) {
        if( is_string( $table ) ) {
            $this->_table = $table;
            $fields = $pairs;
        } else {
            $fields = $table;
        }
        $sql = $this->Build( "insert", $fields );
        $this->_execute( $sql );
        return $this->insertID();
    }
    
   /**
    * Perform a query straight. POTENTIALLY DANGEROUS!
    */
    public function query( $query ) { return $this->_execute( $query ); }
    
}