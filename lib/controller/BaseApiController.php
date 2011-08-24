<?php
class BaseApiController extends WaxController{
  public $allowed_models = array();
  public $allowed_formats = array("json");
  public $default_format = "json";
  
  function __construct($application=false){
    parent::__construct($application);
    //hook to allow generic views if named ones don't exist, i.e. method_missing.html
    $controller_class = get_class($this);
    $controller_parent_class = get_parent_class($this);
    //adding default view paths, to allow overriding, but also have a fallback view
    WaxEvent::add("wax.after_plugin_view_paths", function() use($controller_class, $controller_parent_class){
      foreach((array)Autoloader::view_paths("plugin") as $path) {
        $view = WaxEvent::data();
        $view->add_path($path.$controller_class."/method_missing");
        $view->add_path($path.$controller_parent_class."/method_missing");
        $view->add_path($path."shared/method_missing");
      }
    });
  }
  
  public function method_missing(){
    
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
      }else{
        //handle different HTTP methods, POST/PUT = create/update, DELETE = delete, GET = read
        if($_SERVER['REQUEST_METHOD'] == "POST" || $_SERVER['REQUEST_METHOD'] == "PUT"){
          $model->set_attributes($col_params);
          if(!$model->save()) $this->errors[] = array("message" => "Could not save", "data" => $model->errors);
        }elseif($_SERVER['REQUEST_METHOD'] == "DELETE"){
          if(!$id) $this->errors[] = array("message" => "Can't delete without specifying an id");
          elseif(!$model->primval() || !$model->delete()) $this->errors[] = array("message" => "Could not delete", "data" => $model->errors);
        }else{
          foreach((array)$allowed_params as $name => $value){
            if(
              !$this->run_method_if_exists($model, $name, array($value)) && //run param as a method on the model if that method is defined
              in_array($name, array_keys($model->columns)) //if model method didn't exist for a param and it's a defined column, filter on it instead
            ) $model->filter($model->get_col($name)->col_name, $value);
          }

          if($params["page"]) $model = $model->page($params["page"]);
          elseif(!$id) $model = $model->all();
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
      }else $this->output_obj = $this->convert_to_std_class($model);
    }
  }
  
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
  private function class_exists_without_fatal_errors($class_name){
    return array_key_exists($class_name, Autoloader::$registry["application"]);
  }
  
  /**
   * checks if a method exists on an object before trying to run it
   *
   * @param string $obj
   * @param string $method
   * @param array $arguments
   * @return result of called method if it existed, otherwise null
   */
  private function run_method_if_exists($obj, $method, $arguments = array()){
    if(WaxApplication::is_public_method($obj, $method)) return call_user_func_array(array($obj, $method), $arguments);
  }
  
  /**
   * returns stdClass version of model data, for json encoding
   *
   * @param WaxModel/WaxRecordset $model
   * @return stdClass
   */
  private function convert_to_std_class($model, $recursion_check = array()){
    $class = get_class($model);
    $primval = $model->primval();
    if(!$recursion_check[$class][$primval]){
      $recursion_check[$class][$primval] = true;
      $ret = new stdClass;
      if($model instanceof WaxModel){
        foreach($model->columns as $col_name => $col_data){
          $data = $model->$col_name();
          if($data instanceof WaxModel || $data instanceof WaxRecordset) $ret->$col_name = $this->convert_to_std_class($data, $recursion_check);
          else $ret->$col_name = $data;
        }
      }elseif($model instanceof WaxRecordset){
        $ret->count = $model->count();
        $ret->results = array();
        foreach($model as $row) $ret->results[] = $this->convert_to_std_class($row, $recursion_check);
      }
      unset($recursion_check[$class][$primval]);
      return $ret;
      }
  }
}
?>