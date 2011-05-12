<?php
class BaseApiController extends WaxController{
  public $allowed_models = array();
  public $allowed_formats = array("json");
  public $disallowed_filters = array("page"); //disallow page as a filter, since it's a security risk pulling in a page param from the user
  
  public function method_missing(){
    
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
      $allowed_params = array_diff_key($params, $this->disallowed_filters);
      $col_params = array_intersect_key($allowed_params, $model->columns);
      unset($col_params[$model->primary_key]);
      
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
        
        if($id) $this->model = new WaxRecordset($model, array($model->row));
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
  private function convert_to_std_class($model){
    $ret = new stdClass;
    if($model instanceof WaxModel){
      foreach($model->columns as $col_name => $col_data){
        $data = $model->$col_name();
        if($data instanceof WaxModel || $data instanceof WaxRecordset) $ret->$col_name = $this->convert_to_std_class($data);
        else $ret->$col_name = $data;
      }
    }elseif($model instanceof WaxRecordset){
      $ret->count = $model->count();
      $ret->results = array();
      foreach($model as $row) $ret->results[] = $this->convert_to_std_class($row);
    }
    return $ret;
  }
}
?>