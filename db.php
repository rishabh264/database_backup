<?php

/* ------------------------------------------------------------------- */
/* [Backup mySQL & ZIP]  Backup you mySQL Database #php #class #backup */
/* ------------------------------------------------------------------- */

// SETUP
$config = array(
    'backup_version'        => '1.0.0',             // version of the backup app
    'backup_db_host'        => 'localhost',         // the mySQL host name - most time localhost
    'backup_db_port'        => 3306,                // the mySQL host port - most time 3306 
    'backup_db_user'        => 'root',              // the mySQL user name
    'backup_db_pass'        => '',                  // the mySQL password
    'backup_db_name'        => 'sms',               // database name from config.php
    'backup_use_drop'       => true,                // use DROP TABLE IF EXISTS in dump file
    'backup_include_tables' => array(),             // array with tables to include (if used 'backup_exclude_tables' is ignored)
    'backup_exclude_tables' => array('users'),      // array with tables to exclude
    'backup_file_path'      => __dir__ . '/BACKUP', // no '/' slash at the end
    'backup_use_zip'        => false,               // false = save .sql / true = make ZIP from .sql
    'backup_use_password'   => true,                // use password only for ZIP archive
    'backup_zip_password'   => '',                  // the password for the ZIP archive (HINT: This uses System ZIP and not PHP ZIP function!)
);
/* 
 * TODO: download ZIP or SQL and import uploaded ZIP or SQL to a given database
 */

// INI
$backup = new backup($config);
$dump = $backup->db();

// TEST OUTPUT
echo '<pre>';
echo var_dump($dump);
echo '</pre>';

// CLASS
class backup {

    public $config = null;
    // for ZIP only
    private $datasec = array();
    private $ctrl_dir = array();
    private $eof_ctrl_dir = "\x50\x4b\x05\x06\x00\x00\x00\x00"; // end of Central directory record
    private $old_offset = 0;
  
    public function __construct($config) {

        $this->config = $config;
    }

    public function db() {
        // avoid script runtime timeout
        $max_execution_time = ini_get("max_execution_time");
        set_time_limit(0); // 0 = no timelimit
        // connecz to db
        $mysqli = @mysqli_connect($this->config['backup_db_host'].':'.$this->config['backup_db_port'], $this->config['backup_db_user'], $this->config['backup_db_pass']);
        if ( mysqli_connect_errno() ) {
            return array('warning' => 'no mysql login possible');
        }
        // select db
        // Alternate: $mysqli->query('USE ' . $this->config['backup_db_name']);
        if ( ! $mysqli->select_db($this->config['backup_db_name']) ) {
            return array('warning' => 'can not select db');
        }
        // set charset
        if ( ! $mysqli->query("SET NAMES 'utf8'") ) {
            return array('warning' => 'can not set charset to utf8');
        }
        // build dump header
        $tables = array();
        $contents  = PHP_EOL;
        $contents .= '-- adilbo mySQL Dump' . PHP_EOL;
        $contents .= '-- version ' . $this->config['backup_version'] . PHP_EOL;
        $contents .= '-- https://www.adilbo.com/' . PHP_EOL;
        $contents .= '--' . PHP_EOL;
        $contents .= '-- Host: ' . $this->config['backup_db_host'] . ':' . $this->config['backup_db_port'] . PHP_EOL;
        $contents .= '-- Generation Time: ' . date('r') . PHP_EOL;
        $contents .= '-- Server version: ' . $mysqli->server_info . PHP_EOL;
        $contents .= '-- PHP Version: ' . phpversion() . PHP_EOL;
        $contents .=  PHP_EOL;
        $contents .= 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";' . PHP_EOL;
        $contents .= 'SET time_zone = "+00:00";' . PHP_EOL;
        $contents .=  PHP_EOL;
        // build table dump output
        $contents .= '-- --------------------------------------------------------' . PHP_EOL;
        $contents .= '--' . PHP_EOL;
        $contents .= '-- Database: `' . $this->config['backup_db_name'] . '`' . PHP_EOL;
        $contents .= '--' . PHP_EOL;
        // integrate hint for mac if zip password is used
        if ( empty($this->config['backup_zip_password']) ) {
            $contents .= '-- Hint:' . PHP_EOL;
            $contents .= '-- To extract ZIP on Mac OS don\'t use system unzip,' . PHP_EOL;
            $contents .= '-- it only works with extern unzip software like:' . PHP_EOL;
            $contents .= '-- https://theunarchiver.com/ and https://www.keka.io/en/' . PHP_EOL;
            $contents .= '--' . PHP_EOL;
        }
        $contents .= '-- --------------------------------------------------------' . PHP_EOL;
        $contents .= PHP_EOL;
        $contents .= PHP_EOL;
        $contents .= PHP_EOL;
        // get tables
        // Alternate: 'SHOW TABLES FROM ' . $this->config['backup_db_name']
        // Alternate: 'SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA LIKE \'' . $this->config['backup_db_name'] .'\''
        $results_data = $mysqli->query( 'SHOW TABLES' );
        // ups, no tables to backup in this db
        if ( count($results_data->fetch_array()) < 1 ) {
            return array('warning' => 'no tables in the db');
        }
        // only use include tables
        if ( count($this->config['backup_include_tables']) > 0 ) {
            while ( $row = $results_data->fetch_array() ) {
                if ( in_array($row[0], $this->config['backup_include_tables']) ) {
                    $tables[] = $row[0];
                }
            }
        } else {
        // OR use all except the exclude tables
            while ( $row = $results_data->fetch_array() ) {
                if ( !in_array($row[0], $this->config['backup_exclude_tables']) ) {
                    $tables[] = $row[0];
                }
            }
        }
        // no tables left :-o
        if ( count($tables) < 1 ) {
            return array('warning' => 'no table found');
        }
        // build table dump
        foreach ($tables as $table) {
            $res_status = $mysqli->query('SHOW TABLE STATUS LIKE \'' . $table . '\'');
            $row_status = $res_status->fetch_array();
            $contents .= '-- --------------------------------------------------------' . PHP_EOL;
            $contents .= PHP_EOL;
            $contents .= '--' . PHP_EOL;
            $contents .= '-- Table `' . $table . '`' . PHP_EOL;
            $contents .= '--' . PHP_EOL;
            $contents .= '-- Creation: ' . $row_status['Create_time'] . PHP_EOL;
            $contents .= '-- Last update: ' . $row_status['Update_time'] . PHP_EOL;
            $contents .= '--' . PHP_EOL;
            $contents .= PHP_EOL;
            if ( $this->config['backup_use_drop'] == true ) {
                $contents .= 'DROP TABLE IF EXISTS ' . $table . ';'. PHP_EOL;
                $contents .= PHP_EOL;
            }
            $results_data = $mysqli->query('SHOW CREATE TABLE ' . $table);
            while ( $row = $results_data->fetch_array() ) {
                $contents .= $row[1] . ';' . PHP_EOL . PHP_EOL;
            }
            $results_data = $mysqli->query('SELECT * FROM ' . $table);
            $row_count = $results_data->num_rows;
            $fields = $results_data->fetch_fields();
            $fields_count = count($fields);
            $insert_head = 'INSERT INTO `' . $table . '` (';
            for ($i = 0; $i < $fields_count; ++$i) {
                $insert_head  .= '`' . $fields[$i]->name . '`';
                if ( $i < $fields_count - 1 ) {
                    $insert_head  .= ', ';
                }
            }
            $insert_head .= ')';
            $insert_head .= ' VALUES' . PHP_EOL;
            if ( $row_count > 0 ) {
                $r = 0;
                while ( $row = $results_data->fetch_array() ) {
                    if ( $r == 0 ) {
                        $contents .= '--' . PHP_EOL;
                        $contents .= '-- Data for table `' . $table . '`' . PHP_EOL;
                        $contents .= '--' . PHP_EOL;
                        $contents .= PHP_EOL;
                    }
                    if ( ($r % 400)  == 0 ) {
                        $contents .= $insert_head;
                    }
                    $contents .= '(';
                    for ($i = 0; $i < $fields_count; ++$i) {
                        $row_content = $this->mysqli_escape_mimic($row[$i]); // my\/sqli_real_escape_string
                        $row_content = str_replace('\'\'', 'NULL', $row_content);
                        switch ($fields[$i]->type){
                            case 8: case 3:
                            $contents .=  $row_content;
                            break;
                            default:
                            $contents .= '\'' . $row_content . '\'';
                        }
                        if ( $i < $fields_count - 1 ) {
                            $contents  .= ', ';
                        }
                    }
                    if ( ($r+1) == $row_count || ($r % 400) == 399 ) {
                        $contents .= ');' . PHP_EOL;
                        $contents .= PHP_EOL;
                    } else {
                        $contents .= '),' . PHP_EOL;
                    }
                    ++$r;
                }
            }
        }
        // create the backup path if not exists
        if ( !is_dir($this->config['backup_file_path']) ) {
            mkdir ($this->config['backup_file_path'], 0777, true);
        }
        // build backup file names
        $backup_file_name =  date('Y-m-d_H-i-s') . '__backup__db__'.$this->create_guid();
        $backup_file_name_with_path = $this->config['backup_file_path'] . '/' . $backup_file_name;
        // write dump in .sql file
        if ( !($fp = fopen($backup_file_name_with_path . '.sql' ,'w+')) or !($result = fwrite($fp, $contents)) ) {
            @fclose($fp);
            return false;
        }
        @fclose($fp);
        // use file to generate a zip archive
        if ( $this->config['backup_use_zip'] == true ) {
            // if password is given use system zip function
            if ( $this->config['backup_use_password'] == true and !empty($this->config['backup_zip_password']) ) {
                chdir($this->config['backup_file_path']);
                exec('zip -P ' . $this->config['backup_zip_password'] . ' ' . $backup_file_name_with_path . '.zip' . ' ' . $backup_file_name . '.sql');
            } else {
            // use zip helfer function in this file
                #$zip = new zip();
                $backup_archive_name = $this->config['backup_file_path'] . '/';
                $this->add_file($backup_file_name_with_path . '.sql', $backup_file_name . '.sql');
                if ( !($fd = @fopen($backup_file_name_with_path . '.zip', 'w+b')) or !($ok = fwrite($fd, $this->file())) ) {
                    @fclose($fd);
                    return false;  
                }
                @fclose($fd);
            }
            // delete the .sql file (there is still the zip archive)
            @unlink($backup_file_name_with_path . '.sql');
        }
        // reset script runtime timeout
        set_time_limit($max_execution_time); // set it back to the old value
        // return the sql dump data
        return $contents;
    }

    // alternate for my\/sqli_real_escape_string
    // idea from https://www.php.net/manual/de/function.mysql-real-escape-string.php#101248 - https://stackoverflow.com/a/1162502
    private function mysqli_escape_mimic($input) {
        if(is_array($input))
            return array_map(__METHOD__, $input);
        if(!empty($input) && is_string($input)) {
            $search = array("\\",  "\x00", "\n", "\r", "'", '"', "\x1a");
            $replace = array("\\\\", "\\0", "\\n", "\\r", "\'",  '\"',  "\\Z");
            return str_replace($search, $replace, $input);
        }
        return $input;
    }

    // create GUID (Globally Unique Identifier)
    // idea from https://www.php.net/manual/de/function.com-create-guid.php#124635
    private function create_guid() { // 
        $guid = '';
        $namespace = rand(11111, 99999);
        $uid = uniqid('', true);
        $data = $namespace;
        $data .= $_SERVER['REQUEST_TIME'];
        $data .= $_SERVER['HTTP_USER_AGENT'];
        $data .= $_SERVER['REMOTE_ADDR'];
        $data .= $_SERVER['REMOTE_PORT'];
        $hash = strtoupper(hash('ripemd128', $uid . $guid . md5($data)));
        $guid = substr($hash,  0,  8) . '-' .
                substr($hash,  8,  4) . '-' .
                substr($hash, 12,  4) . '-' .
                substr($hash, 16,  4) . '-' .
                substr($hash, 20, 12);
        return $guid;
    }

    // IDEA FROM http://apigen.juzna.cz/doc/EllisLab/CodeIgniter/source-class-CI_Zip.html#18-420
    // https://github.com/chamilo/pclzip/blob/master/pclzip.lib.php
    // http://old.phpconcept.net/pclzip/man/en/index.php?understand
    private function add_file($data, $name) {
        $fp = fopen($data,"r");
        #$data = fread($fp,filesize($data));
        if (filesize($data) > 0) $data = fread($fp,filesize($data));// NEW FROM adilbo
        fclose($fp);
        $name = str_replace('\\', '/', $name);
        $unc_len = strlen($data);
        $crc = crc32($data);
        #$zdata = gzcompress($data);
        if (function_exists('gzcompress')) $zdata = gzcompress($data,9); else $zdata = $data;// NEW FROM adilbo
        $zdata = substr ($zdata, 2, -4);
        $c_len = strlen($zdata);
        $fr = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00";
        $fr .= "\x00\x00\x00\x00";
        $fr .= pack("V",$crc);
        $fr .= pack("V",$c_len);
        $fr .= pack("V",$unc_len);
        $fr .= pack("v",strlen($name));
        $fr .= pack("v", 0);
        $fr .= $name;
        $fr .= $zdata;
        $fr .= pack("V",$crc);
        $fr .= pack("V",$c_len);
        $fr .= pack("V",$unc_len);
        $this->datasec[] = $fr;
        $new_offset = strlen(implode('', $this->datasec));
        $cdrec = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00";
        $cdrec .="\x00\x00\x00\x00";
        $cdrec .= pack("V",$crc);
        $cdrec .= pack("V",$c_len);
        $cdrec .= pack("V",$unc_len);
        $cdrec .= pack("v",strlen($name));
        $cdrec .= pack("v",0);
        $cdrec .= pack("v",0);
        $cdrec .= pack("v",0);
        $cdrec .= pack("v",0);
        $cdrec .= pack("V",32);
        $cdrec .= pack("V",$this->old_offset);
        $this->old_offset = $new_offset;
        $cdrec .= $name;
        $this->ctrl_dir[] = $cdrec;
    }
    private function file() {
        $data = implode('',$this->datasec);
        $ctrldir = implode('',$this->ctrl_dir);
        return
            $data .
            $ctrldir .
            $this -> eof_ctrl_dir .
            pack("v", sizeof($this->ctrl_dir)) .
            pack("v", sizeof($this->ctrl_dir)) .
            pack("V", strlen($ctrldir)) .
            pack("V", strlen($data)) .
            "\x00\x00";
    }

}