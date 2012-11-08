<?php

namespace RocketSoftware\u2\RedBack;

use RocketSoftware\u2\RedBack\Gateway\cgi;
use RocketSoftware\u2\RedBack\Gateway\Socket;
use RocketSoftware\u2\RedBack\uAssocArray;

/*
 * The values are normally defined here but if RocketSoftware\u2\Redback\uArray.php is loaded first then it will be defined there.
 */
if (!defined('AM')) {
  /*
   * standard PICK defines to make it easier to convert multi-valued data to
   * something that can be used by PHP
   */

  /**
   * PICK Attribute Mark (AM)
   */
  define("AM", chr(254));

  /**
   * PICK Value Mark (VM)
   */
  define("VM", chr(253));

  /**
   * PICK Sub-Value Mark (SV or SVM)
   */
  define("SV", chr(252));
}

/**
 * uObject class.
 *
 * DB_RedBack class is the main class which is used to access your RedBack
 * Server
 *
 * @package RocketSoftware\u2\RedBack\uObject
 */
class uObject {

  /**
   * In debug mode, communication data is stored here.
   *
   * When object has been put into debug mode the communication between
   * the gateway and the RedBack Object Server will be recorded here.
   *
   * @access public
   */
  public $__Debug_Data = array();

  /**
   * The handle of the object which is used to re-associate back the same
   * object.
   *
   * This is a handle that can be saved in a session/cookie/form/query
   * string and used during consecutive page requests to open the same
   * object again.
   *
   * @access public
   */
  public $RBOHandle = NULL;

  /**
   * The factory will open the DB_RedBack object and load the required
   * communication method.
   *
   * @since 29/11/2005
   *
   * @param  string $handler A pointer to the handler which is to be
   *              used to communicate with the RedBack
   *              scheduler
   *
   * @param  string $url  A string which contains the path to the U2
   *             RedBack Server. This can be in the form of a
   *             standard uri for a web server if the cgi
   *             gateway is being used or a host:port if the
   *             communication is directly with the RedBack
   *             Scheduler.
   *
   * @param  string $object An identfy which represents which RedBack
   *             object is to be opened.
   *
   * @param  string $user  Name of the user the RedBack user to which
   *             this object is to be opened as.
   *
   * @param  string $pass  Password for the user.
   *
   * @return object
   *
   * @access public
   */
  public static function factory($handler, $url = '', $object = '', $user = NULL, $pass = NULL) {
    $class  = '\\RocketSoftware\\u2\\RedBack\\Gateway\\' . $handler;

    return new $class($url, $object, $user, $pass);
  }

  /**
   * DB_RedBack constructor
   *
   * @param  string $url  A string which contains the path to the U2
   *             RedBack Server. This can be in the form of a
   *             standard uri for a web server if the cgi
   *             gateway is being used or a host:port if the
   *             communication is directly with the RedBack
   *             Scheduler.
   *
   * @param  string $object An identfy which represents which RedBack
   *             object is to be opened.
   *
   * @param  string $user  Name of the user the RedBack user to which
   *             this object is to be opened as.
   *
   * @param  string $pass  Password for the user.
   *
   * @return NULL
   *
   * @access public
   */
  public function __construct($url = '', $object = '', $user = NULL, $pass = NULL) {
    $this->_readini();

    if (array_key_exists('Parameters', $this->_ini_parameters)) {
      foreach ($this->_ini_parameters['Parameters'] as $k => $v) {
        switch ($k) {
          case 'debug':
            $this->__setDebug($v ? TRUE : FALSE);
            break;
          case 'monitor':
            $this->__setMonitor($v ? TRUE : FALSE);
            break;
          case 'log':
            if (class_exists('Log') && $v) {
              $this->_logger = &Log::factory('file', $v, 'redback', array('buffering' => TRUE));
            }
        }
      }
    }

    if ($url && $object) {
      $this->open($url, $object, $user, $pass);
    }
  }

  /**
   * close of the DB_RedBack object
   *
   * The descructor checks to make sure that the are no tainted properties
   * and if there is it will send a refresh() method to the scheduler to
   * update these on the server. If the logging facility has been used
   * then the log file will be closed.
   *
   * @access public
   */

  public function __destruct() {
    $this->close();
    if (is_object($this->_logger)) {
      $this->_logger->close();
    }
  }

  /**
   * The overloaded __set() allows the RBO properties to be exported.
   *
   * Overloading gives the PHP RedBack gateway the advantage in that over
   * other gateways in that properties are able to be exported as if they
   * were normal properties within this object.
   *
   * <code>
   * $rbobj->name = 'John Doe';
   * </code>
   *
   * is the same as the following
   *
   * <code>
   * $rbobj->set('name', 'John Doe');
   * </code>
   *
   * sometime the need will a raise when you will need to use the
   * set() method instead of the overloaded function.
   *
   * @access public
   */
  public function __set($property, $value) {
    if ($this->checkAccess($property)) {
      $this->set($property, $value);
    }
    else {
      trigger_error(sprintf('Undefined property: %s::%s.', get_class($this), $property), E_USER_ERROR);
    }
  }

  /**
   * Allow RBO properties to be retrieved as if they were apart of the
   * object
   *
   * This is an alias for the getproperty method, which allows the PHP
   * developer to treat the RBO property as if it were are a part of the
   * PHP object. This will make it easier for the PHP developer to
   * understand without having to deal with how PICK works.
   *
   * If a property does not exist in the RBO then access to the property
   * will fail.
   *
   * @access public
   */

  public function __get($property) {
    if ($this->checkAccess($property)) {
      return $this->get($property);
    }
    else {
      throw new \Exception(sprintf('Undefined property: %s::%s.', get_class($this), $property));
    }
  }

  /**
   * Allow RBO methods to be called as if they are normal PHP methods.
   *
   * When calling RedBack methods, any arguments are ignored, as they are
   * not used by the RedBack Scheduler
   *
   * @access public
   */

  public function __call($method, $args) {
    return $this->callmethod($method);
  }

  /**
   * This method will open a connection to the RBO and retrieve
   * the properties of the Object.
   *
   * @access public
   *
   * @param string $url   The network to the RedBack Object server.
   *             This will depending on which factory has been
   *             loaded, or it can be listed in the phprgw.ini
   *             file.
   *
   * @param string $obj   The description of the Object that is going
   *             to be connected to.
   *
   * @param string $user   An optional field that when included will
   *             allow the user to be authenticated.
   *
   * @param string $pass   If the user has been specified then the
   *             password will also need to be specified to
   *             allow the authenication to complete.
   *
   *             Also note that if you are using
   *             authentication then an additional call the
   *             RedBack Scheduler will be made.
   */
  public function open($url, $obj, $user = NULL, $pass = NULL) {
    if ($user) {
      if (!$this->_authorise($url, $obj, $user, $pass)) {
        return FALSE;
      }
    }
    return $this->_open($url, $obj);
  }

  /*
   * When the object is closed make sure that all updated properties have
   * been sent to the RBO Server.
   *
   * This function is automatically called by the destructor.
   */
  public function close() {
    if ($this->RBOHandle && $this->_tainted) {
      $this->_callmethod(',.Refresh()');
    }
  }

  /**
   * Call a RBO method.
   *
   * To call a RBO method user this function or use the overloaded option
   * which will allow the developer to call the method as if it were a
   * normal PHP method.
   *
   * As there is no method inside the object to validate that the method
   * is valid without passing the command to the RedBack Scheduler then
   * the return needs to be checked to make sure it is not FALSE.
   *
   * @access public
   *
   * @param string $method  Name of the method that is to be called.
   *
   * @return mixed      If there has been an error in calling this
   *             method then FALSE will be returned,
   *             otherwise TRUE. If this object is a uQuery
   *             object and the Select or PageDisp methods
   *             were called a DB_RedBack_RecordSet will be
   *             returned.
   */

  public function callmethod($method) {
    $ret = $this->_callmethod("{$this->_object},this.{$method}");

    // Check that there are no major errors.
    if (isset($this->_properties['HID_ERROR']) && $this->_properties['HID_ERROR'] > 0) {
      throw new \Exception($this->get('HID_ALERT', TRUE));
    }

    return $ret;
  }

  /**
   * Set a RBO property to a new value.
   *
   * set() will set the an RBO property to any desired value.
   *
   * @access public
   *
   * @param  mixed  $property  This can be specified as either the name
   *               of the property to be set or an array
   *               which has the properties listed as keys
   *               and values will set multiple values at
   *               once.
   * @param  mixed  $value   This value is a formated as an array
   *               which represents a multi-valued field.
   *
   * @param  bool  $override  Allows the developer to update any
   *               internal RedBack properties. Use this
   *               with caution as if the values are set
   *               incorrectly then this may cause unknown
   *               issues.
   */

  public function set($property, $value = array(), $override = FALSE) {
    if (is_array($property)) {
      // process array of values to set
      foreach ($property as $k => $v) {
        if ($override || $this->checkAccess($k)) {
          $this->_properties[$k]['data']->set($v);
          $this->_properties[$k]['tainted'] = TRUE;
          $this->_tainted = TRUE;
        }
      }
    }
    else {
      if ($override || $this->checkAccess($property)) {
        $this->_properties[$property]['data']->set($value);
        $this->_properties[$property]['tainted'] = TRUE;
        $this->_tainted = TRUE;
      }
      else {
        trigger_error(sprintf('Undefined property: %s::%s.', get_class($this), $property), E_USER_ERROR);
      }
    }
  }

  /**
   * Return the value of a RBO property.
   *
   *
   * @param  string $property  The name of the propety that needs to be
   *               returned.
   *
   * @param  bool  $override  Used to retrieve values of internal
   *               RedBack fields
   *
   * @return uArray return the uArray object for the propetry specified
   */

  public function get($property, $override = FALSE) {
    if (array_key_exists($property, $this->_properties) && $override || $this->checkAccess($property)) {
      return $this->_properties[$property]['data'];
    }
    return FALSE;
  }

  /**
   * Check the the fields exist and are accessible.
   */
  public function checkAccess($property) {
    if (array_key_exists($property, $this->_properties)) {
      if ($this->_debug_mode) {
        return TRUE;
      }
      else {
        if (preg_match('/^HID_/', $property)) {
          return FALSE;
        }
        else {
          return TRUE;
        }
      }
    }
    else {
      return FALSE;
    }
  }
  
  /**
   * Fetch an associated array of the defined fields
   */
  public function fetchAssoc(array $fields) {
    return new uAssocArray($this, $fields);
  }
  
  /**
   * Return an array of all the errors that have been set.
   *
   * Any errors that have occured since the last method call whill be
   * returned in the field as an array
   *
   * @access public
   * @return array  An array of all the errors that have occured.
   */
  public function __getError() {
    return explode("\n", $this->get('HID_ALERT', TRUE));
  }

  /**
   * Turns on and off the RedBack Scheduler monitor which returns a
   * statistics on how long it took to do certain methods.
   *
   * @access public
   * @param  bool  $mode  If this field is ommited then the monitor
   *             will be toggled between on and off. If TRUE
   *             or FALSE is specified then this value will
   *             be used.
   *
   */

  public function __setMonitor($mode = NULL) {
    $this->_monitor = $mode !== NULL ? $this->_monitor = $mode : ($this->_monitor ? FALSE : TRUE);
  }

  /**
   * Allows the developer to record all the information that has been past
   * between this object and the RedBack Scheduler.
   *
   * The transactional information is stored in $this->__Debug_Data Use
   * the following to display this information.
   *
   * <code>
   * print_r($obj->__Debug_Data);
   * </code>
   *
   * @access public
   * @param  mixed  $mode  TRUE or FALSE will set the debug mode to
   *             this value or if no value is past the debug
   *             module will be toggled.
   */

  public function __setDebug($mode = NULL) {
    $this->_debug_mode = $mode !== NULL ? $this->_debug_mode = $mode : ($this->_debug_mode ? FALSE : TRUE);
  }
  
  /**
   * Returns all the statistical data from the RedBack monitor.
   */

  public function __getStats() {
    if (is_array($this->_monitor_data)) {
      foreach ($this->_monitor_data as $k => $v) {
        if (isset($v['data'])) {
          $stats = array();
          foreach (explode("\n", $v['data']) as $s) {
            if (preg_match('/\[(.*)\]/', $s, $match)) {
              $group = $match[1];
            }
            elseif ($group && preg_match('/^(.*)=(.*)$/', $s, $match)) {
              $stats[$group][$match[1]] = $match[2];
            }
          }
          unset($this->_monitor_data[$k]['data']);
          $this->_monitor_data[$k] = array_merge($this->_monitor_data[$k],$stats);
        }
      }
    }
    return $this->_monitor_data;
  }

  /*
   * Private varibles
   */
  /**
   * Specifies which comms layer to use.
   *
   * @deprecated Removed in favour of factory creation.
   * @access private
   */
  protected $_comms_layer = '';
  protected $_url_parts = '';
  protected $_object = '';
  protected $_properties = NULL;
  protected $_tainted = FALSE;
  protected $_debug_mode = FALSE;
  protected $_monitor = FALSE;
  protected $_monitor_data = NULL;
  protected $_return_mode = 18;
  protected $_ini_parameters = array();
  protected $_logger = NULL;

  /*
   * Private Functions
   */

  protected function _open($url, $object) {
    $this->_url_parts = parse_url($url);
    if (count($this->_url_parts) == 1) {
      if (array_key_exists($this->_url_parts['path'], $this->_ini_parameters['Databases'])) {
        $this->_url_parts = parse_url($this->_ini_parameters['Databases'][$this->_url_parts['path']]);
      }
    }
    if (preg_match("/\xfd/", $object)) {
      $handle = explode(':', $object);
      $this->_properties['HID_FORM_INST']['data'] = new DB_RedBack_Array($handle[0]);
      $this->_properties['HID_USER']['data'] = new DB_RedBack_Array($handle[1]);
      $object = ',.Refresh()';
    }
    $ret = $this->_callmethod($object);

    if (isset($this->_properties['HID_ERROR']) && $this->_properties['HID_ERROR'] > 0) {
      throw new \Exception($this->_properties['HID_ALERT']['data']);
    }

    $this->RBOHandle = $this->_properties['HID_FORM_INST']['data'] .':' .$this->_properties['HID_USER']['data'];
    $this->_object = isset($this->_properties['HID_HANDLE']) ? $this->_properties['HID_HANDLE']['data'] : '';
    return $ret;
  }

  /**
   * @access private
   */

  protected function _readini() {
    global $__RedBack_ini;

    if (!$__RedBack_ini) {
      $ini_path = DIRECTORY_SEPARATOR == '\\' ? array('.', 'C:\\winnt') : array('.', '/etc');
      foreach ($ini_path as $directory) {
        $file = $directory . DIRECTORY_SEPARATOR .'phprgw.ini';
        if (file_exists($file)) {
          $__RedBack_ini = @parse_ini_file($file, TRUE);
          break;
        }
        else {
          $__RedBack_ini = array();
        }
      }
    }
    $this->_ini_parameters = $__RedBack_ini;
  }

  protected function _authorise($url, $obj, $user, $pass) {
    $obj_parts = explode(':', $obj);
    $this->_open($url, "{$obj_parts[0]}:RPLOGIN");
    $this->set('USERID', $user);
    $this->set('PASSWORD', $pass);
    if ($this->callmethod('ADOLogin')) {
      $props = array();
      $props['HID_FORM_INST'] = $this->_properties['HID_FORM_INST'];
      $props['HID_USER'] = $this->_properties['HID_USER'];
      $this->_properties = $props;
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  protected function _callmethod($method) {
    return FALSE;
  }

  protected function _build_data() {
    // create post data
    if ($this->_properties) {
      foreach ($this->_properties as $k => $v) {
        if (isset($v['tainted']) && $v['tainted'] || $k == 'HID_FORM_INST' || $k == 'HID_USER') {
          $data[] = "$k=" .urlencode($v['data']);
          unset($this->_properties[$k]['tainted']);
        }
        if (preg_match('/HID_ROW_\d+/', $k)) {
          unset($this->_properties[$k]);
        }
      }
      if (isset($data)) {
        $data[] = 'redbeans=1';
      }
      $data = implode('&', $data);
    }
    else {
      $data = 'redbeans=1';
    }
    if ($this->_monitor) {
      $data.= "&MONITOR=1";
    }
    return $data;
  }
}