<?php
class BaseApiController extends WaxController{
  public $allowed_models = array();
  public $allowed_formats = array("json", "xml");
  public $default_format = "json";
  public $default_per_page = 10;
  public static $added_view_path_hook;
  
  function __construct($application=false){
    parent::__construct($application);
    
    //hook to allow generic views if named ones don't exist, i.e. method_missing.html
    if(!self::$added_view_path_hook){
      $extra_view_paths = array();
      foreach(Autoloader::view_paths("user") as $path) {
        $extra_view_paths[] = $path.WaxUrl::get("controller")."/method_missing";
        $extra_view_paths[] = $path."shared/method_missing";
        $extra_view_paths[] = $path."method_missing";
      }
      
      //adding default view paths, to allow overriding, but also have a fallback view
      WaxEvent::add("wax.after_local_view_paths", function() use($extra_view_paths){
        WaxEvent::data()->template_paths = array_merge(WaxEvent::data()->template_paths, $extra_view_paths);
      });
      
      $extra_view_paths = array();
      foreach((array)Autoloader::view_paths("plugin") as $path) {
        $extra_view_paths[] = $path.get_class($this)."/method_missing";
        $extra_view_paths[] = $path.get_parent_class($this)."/method_missing";
        $extra_view_paths[] = $path."shared/method_missing";
      }
      
      //adding default view paths, to allow overriding, but also have a fallback view
      WaxEvent::add("wax.after_plugin_view_paths", function() use($extra_view_paths){
        WaxEvent::data()->template_paths = array_merge(WaxEvent::data()->template_paths, $extra_view_paths);
      });
      
      self::$added_view_path_hook = 1;
    }
  }
  
  public function method_missing(){
    set_time_limit(0); //no time limit, in case the request is massive
    
    //access control for models, throwing a standard 404
    if(!in_array($this->action, $this->allowed_models)) throw new WXRoutingException("No Public Action Defined for - ".$this->action." in controller ".get_class($this).".", "Missing Action");
    //the line below has a static var check that is equivalent to class_exists, but won't trigger a fatal error from trying to autoload
    elseif(!$this->class_exists_without_fatal_errors($model_class = Inflections::camelize($this->action, true))){
      $this->errors[] = array("message" => "No data model defined for $this->action.");
    }else{
      $id = Request::param("id");
      $model = new $model_class($id);
      
      //separate post or get vars that exist on the model from ones that don't
      $params = array_merge(array_diff_key($_GET, array("route"=>0)), $_POST);
      
      //allow a model to map each column/filter to another via a mapping array
      foreach((array)$model->api_col_mapping as $k => $v){
        $params[$v] = $params[$k];
        unset($params[$k]);
      }
      
      $col_params = array_intersect_key($params, $model->columns);
      unset($col_params[$model->primary_key]);
      $allowed_params = array_merge($col_params, array_intersect_key($params, array_flip((array)$model->allowed_api_filters)));
      
      //run access method on model if it exists
      if(WaxApplication::is_public_method($model, "access") && !($user = $this->run_method_if_exists($model, "access", array($params)))){
        $this->errors[] = array("message" => "Access denied, please refer to our documentation for more details.");
      //hook to override api handling completely per model
      }elseif(WaxApplication::is_public_method($model, "api_override")){
        $model->api_override($this);
      }else{
        //handle different HTTP methods, POST/PUT = create/update, DELETE = delete, GET = read (default)
        if($_SERVER['REQUEST_METHOD'] == "POST" || $_SERVER['REQUEST_METHOD'] == "PUT"){
          if($this->use_format && method_exists($this, "handle_post_".$this->use_format)) $model = call_user_func(array($this, "handle_post_" . $this->use_format), $model_class);
          else{
            $model->set_attributes($col_params);
            if(!$model->save()) $this->errors[] = array("message" => "Could not save", "data" => $model->errors);
          }
        }elseif($_SERVER['REQUEST_METHOD'] == "DELETE"){
          if(!$id) $this->errors[] = array("message" => "Can't delete without specifying an id");
          elseif(!$model->primval() || !$model->delete()) $this->errors[] = array("message" => "Could not delete", "data" => $model->errors);
        }elseif(!$model->primval()){ //GET or anything else
          
          //first apply relevant filters
          foreach((array)$allowed_params as $name => $value){
            if(
              !$this->run_method_if_exists($model, $name, array($value)) && //run param as a method on the model if that method is defined
              in_array($name, array_keys($model->columns)) //if model method didn't exist for a param and it's a defined column, filter on it instead
            ) $model->filter($model->get_col($name)->col_name, $value);
          }
          
          if($params["per_page"] === "0") $model = $model->all();
          else $model = $model->page($params["page"]?$params["page"]:1, $params["per_page"]?$params["per_page"]:$this->default_per_page);
        }
        
        //expose model to views, to keep consistency for rendering even single models are converted to a 1-row recordset
        if($model instanceof WaxModel) $this->model = new WaxRecordset($model, array($model->row));
        else $this->model = $model;
      }
    }
    
    //prep for json output, might move this into the view at a later stage
    if($this->use_format == "json"){
      if($this->errors){
        $this->output_obj = new stdClass;
        $this->output_obj->errors = $this->errors;
      }else $this->output_obj = $this->wax_model_to_array($model);
    }
  }
  
  /**
   * help action to generate dynamic help based on model code
   */
  public function help(){
    //set_environment returns false on failure, null on success. testing for false.
    if(Config::set_environment(ENV.'help') !== false){
      WaxModel::load_adapter(Config::get('db'));
      $this->use_actual_data = 1;
    }
    
    $comment_tokens = array("T_COMMENT", "T_DOC_COMMENT");
    $comment_blocks = array(
      "#/\*+\s#ms",             // Multi Start
      "#\*+/#ms",               // Multi Block End
      "#/\*+([^\n]*)\*+/#ms",   // Multi Block on one line
      "#\s*\*([^\n]*\n)#ms",      // Multi Block
      "#//([^\n]*)#ms"          // Single Comment Line
    );
    $token_search_window = 10;
    
    $this->use_layout = "help";
    foreach($this->allowed_models as $model){
      $this->doc_classes[$model]['filters'] = array();
      
      $class = Inflections::camelize($model, true);
      
      //first, look for functions with docs to use
      foreach(Autoloader::$registry_chain as $responsibility)
        if(Autoloader::$registry[$responsibility][$class])
          $fname = Autoloader::$registry[$responsibility][$class];
      
      if(!$fname) continue;
      
      if(!$this->use_actual_data || !($instance = $this->doc_classes[$model]['model'] = $class::find("first", array("order"=>"RAND()")))){
        $instance = $this->doc_classes[$model]['model'] = new $class;
        $instance->{$instance->primary_key} = -1;
      }
      
      //build documentation using php tokens
      $file = file_get_contents($fname);
      $code = "";
      $tokens = token_get_all($file);
      foreach($tokens as $i => $tok) {
        if(is_array($tok) && token_name($tok[0]) == "T_FUNCTION" && is_array($tokens[$i+2]) && in_array($tokens[$i+2][1], (array)$instance->allowed_api_filters)) {
          //search 10 tokens back to try find comments relevant to the function
          for($j = $i; $j > $i - 10; $j--){
            //stop looking on single char tokens, these are like ;}) etc. this will prevent pulling in comments from other code blocks
            if(!is_array($tok_j = $tokens[$j])) break;
            if(is_array($tok_j = $tokens[$j]) && in_array(token_name($tok_j[0]), $comment_tokens)){
              $help = preg_replace($comment_blocks, "$1", $tok_j[1]);
              $this->doc_classes[$model]['filters'][$tokens[$i+2][1]] = $help;
              break;
            }
          }
          if(!$this->doc_classes[$model]['filters'][$tokens[$i+2][1]]) $this->doc_classes[$model]['std_filters'][] = $tokens[$i+2][1];
        }
      }
      
      //now, add columns to the docs
      $skip_cols = array_merge($this->doc_classes[$model]['filters'], array($instance->primary_key=>0)); //skip primary key, and matching functions
      foreach(array_diff_key($instance->columns, $skip_cols) as $col => $col_data) if(!$col_data[1]['skip_api_filter_help']){
        
        //add in dummy data if we're not using actual database data
        if($instance->primval == -1){
          if($col_data[0] == "CharField") $instance->$col = "Character Field Test Data";
          elseif($col_data[0] == "IntegerField" || $col_data[0] == "FloatField") $instance->$col = 0;
          elseif($col_data[0] == "DateTimeField") $instance->$col = time();
        }
        
        if($custom_col_help = $col_data['api_filter_help']) $this->doc_classes[$model]['filters'][$col] = $custom_col_help;
      }
    }
  }
  
  /**
   * only checks the application registry for speed
   */
  public function class_exists_without_fatal_errors($class_name){
    return array_key_exists($class_name, Autoloader::$registry["application"]) || array_key_exists($class_name, Autoloader::$registry["plugin"]);
  }
  
  /**
   * checks if a method exists on an object before trying to run it
   *
   * @param string $obj
   * @param string $method
   * @param array $arguments
   * @return result of called method if it existed, otherwise null
   */
  public function run_method_if_exists($obj, $method, $arguments = array()){
    if(WaxApplication::is_public_method($obj, $method)) return call_user_func_array(array($obj, $method), $arguments);
  }
  
  /**
   * returns stdClass version of model data, for json encoding
   *
   * @param WaxModel/WaxRecordset $model
   * @return array
   */
  public function wax_model_to_array($model, $recursion_check = array()){
    $class = get_class($model);
    $primval = $model->primval();
    if(!$recursion_check[$class][$primval]){
      $recursion_check[$class][$primval] = true;
      $ret = array();
      if($model instanceof WaxModel){
        foreach($model->columns as $col_name => $col_data){
          $data = $model->$col_name();
          if($data instanceof WaxModel || $data instanceof WaxRecordset) $ret[$col_name] = $this->wax_model_to_array($data, $recursion_check);
          else $ret[$col_name] = $data;
        }
      }elseif($model instanceof WaxRecordset){
        $ret["count"] = $model->count();
        $ret["results"] = array();
        foreach($model as $row) $ret["results"][] = $this->wax_model_to_array($row, $recursion_check);
      }
      unset($recursion_check[$class][$primval]);
      return $ret;
      }
  }
  
  /**
   * reverse of above, saves data in the array back to the database
   */
  public function array_to_wax_model($array, $class){
    $model = new $class;
    $matched_columns = array_intersect(array_keys($array), array_keys($model->columns)); //cut out cols in the data that don't match the cols on the model
    
    //if there are any column names matching at this level of the array, assume this level is a row. otherwise try multiple rows.
    if($matched_columns){
      foreach($matched_columns as $col){
        if($value = $array[$col]){
          if(in_array($model->columns[$col][0], array("ForeignKey", "HasManyField", "ManyToManyField"))){
            if(!($target_model = $model->columns[$col][1]["target_model"])) $target_model = $model->get_col($col)->target_model;
            $underscored_class = underscore($class);
            if($array[$underscored_class]) $array = $array[$underscored_class];
            $model->$col = $this->array_to_wax_model($value, $target_model);
          }else{
            //hack for weird values that end up in arrays from json_encode then json_decode
            if(is_array($value)) $model->$col = $value[0];
            else $model->$col = (string)$value;
          }
        }
      }
      $model->validation_groups = array("dont validate at all, that's not for the api");
      $model->save();
      return $model;
    }else{
      $rowset = array();
      foreach($array as $row){
        $rowset[] = $this->array_to_wax_model($row, $class)->row;
      }
      return new WaxRecordset($model, $rowset);
    }
  }
  
  /**
   * convert simplexml to multidimensional array
   *
   * @param string $xml 
   * @return array
   */
  public function simple_xml_to_array($xml){
    return json_decode(json_encode($xml),TRUE);
  }
  
  public function handle_post_xml($class){
    $xml = simplexml_load_string(file_get_contents('php://input'));
    $array = $this->simple_xml_to_array($xml);
    if($array["results"]) $array = $array["results"]["result"];
    return $this->array_to_wax_model($array, $class);
  }
  
}
?>