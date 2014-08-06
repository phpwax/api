<?php
class BaseApiController extends WaxController{

  public $allowed_models = array();
  public $allowed_formats = array("json", "xml");
  public $header_types = array('json'=>'application/javascript', 'xml'=>'application/xml');
  public $model_class = false;
  public $model = false;
  public $api_scope = "api";
  public $per_page = 50;
  public $this_page = 1;
  public $column_map = array();
  public $errors = array();
  public $flat_models = array();
  public $flat_mode = false;

  public function controller_global(){
    set_time_limit(0);
    if($header = $this->header_types[$this->use_format]) header("Content-Type: $header");
    if(!in_array($this->action, $this->allowed_models)) throw new WXRoutingException('The model you are looking for is not available', "Model not found", '404');
    elseif($this->model_class = Inflections::camelize($this->action, true)) $this->model = new $this->model_class($this->api_scope);
    else throw new WXRoutingException('Error', "Model Error", '404');

    //set format
    if(!$this->use_format) $this->use_format = $this->allowed_formats[0];
    //set view - use cms view
    if($_SERVER['REQUEST_METHOD'] == "POST" || $_SERVER['REQUEST_METHOD'] == "PUT") $this->action = "process_api_write";
    else $this->action = "process_api_request";
    $this->use_view = "process_api_request";
  }

  //from the model find and spit out all data
  public function process_api_request(){
    $params = $_REQUEST;
    //remove the route param from htacces redirect, and the auth is handled by the scope
    unset($params['route'], $params['auth_token']);
    //find columns in get/post that match model columns for filters
    $filter_keys = array_intersect_key($params, $this->model->columns);
    //apply filters to the model based on their type
    foreach($filter_keys as $column=>$values) $this->model = $this->filter_model($this->model, $column, $values);
    //find results
    if($per_page = Request::param('per_page')) $this->per_page = $per_page;
    if($page = Request::param('page')) $this->this_page = $page;
    $this->results = $this->model->page($this->this_page, $this->per_page);

  }

  //parse incoming data and write out to the model, reverse of process_api_request
  public function process_api_write(){
    $data = file_get_contents('php://input');
    if($this->use_format == "xml"){
      libxml_use_internal_errors(1);
      $data = json_encode(simplexml_load_string($data));
      if($errors = libxml_get_errors()) $this->errors[] = array("type"=>"xml parse", "details"=>json_decode(json_encode($errors), 1));
    }
    $parsed = json_decode($data, 1);
    if($parsed === null) $this->errors[] = array("type"=>"json parse", "error"=>json_last_error());
    else $this->results = $this->write_model($parsed, $this->model);
    
    // Specify flat mode if applicable
    if (in_array($this->action, $this->flat_models)) {
        $this->flat_mode = true;
    }
  }




  /**
   * based on the join type of the column being filtered, call other functions
   * or return the standard filter
   */
  protected function filter_model($model, $col, $values){
    $col_type = $model->columns[$col][0];
    //if its a join that we need to find the other side of, go fetch it
    if($col_type == "ManyToManyField") return $this->filter_many_to_many($model, $col, $values);
    else if($col_type == "HasManyField") return $this->filter_has_many($model, $col, $values);
    else return $model->filter($model->get_col($col)->col_name, $values);
  }
  /**
   * many to many filtering
   * - from the column data it finds the join table and
   *   creates a fake model for that table, finds joins,
   *   fetches results and adds the filtes on the main model
   */
  protected function filter_many_to_many($model, $col, $values){
    if(!is_array($values)) $values = array($values);
    $primaries = array(0);
    //find target model
    $target_class = $model->columns[$col][1]['target_model'];
    $target = new $target_class;
    //work out the column names on both sides
    $target_col = $target->table."_".$target->primary_key;
    $model_join_col = ($model->columns[$col][1]['join_field']) ? $model->columns[$col][1]['join_field'] : $model->table."_".$model->primary_key;
    $fake_model = new WaxModel;
    //work out the join table name
    if($target->table < $model->table) $join_table = $target->table."_".$model->table;
    else $join_table = $model->table ."_".$target->table;
    //fetch the details
    $fake_model->table = $join_table;
    foreach($fake_model->filter($target_col, $values)->all() as $row) $primaries[] = $row->$model_join_col;

    return $model->filter($model->primary_key, $primaries);
  }

  protected function filter_has_many($model, $col, $values){
    if(!is_array($values)) $values = array($values);
    $target_class = $model->columns[$col][1]['target_model'];
    $target = new $target_class;
    $model_join_col = ($model->columns[$col][1]['join_field']) ? $model->columns[$col][1]['join_field'] : $model->table."_".$model->primary_key;
    $ids = array(0);
    foreach($target->filter($model_join_col, $values)->all() as $row) $ids[] = $row->primval;
    return $model->filter($model->primary_key, $ids);
  }

  /**
   * write from array based data into waxmodels
   * handles multilevel arrays by recursion
   * expects data[results] sub array with a set of models
   * returns WaxRecordset of successfully saved models
   */
    protected function write_model($data, WaxModel $empty_model){
        $rowset = array();
        $results = $data["results"];

        //hack for xml input result sets: our xml->json->array hack above means that incoming arrays in xml aren't encoded "right"
        if (is_numeric(reset(array_keys(reset($results))))) {
            $results = reset($results);
        }
        
        foreach($results as $result){
            $model = clone $empty_model;

            //don't accept columns that aren't defined on the model
            $result = array_intersect_key($result, $empty_model->columns);

            //save associations after values to handle new rows correctly
            //including foreign keys as associations to be handled similarly to hasmany/manytomany
            foreach($model->columns as $column => $data){
                $type = $data[0];
                if(($type == "HasManyField" || $type == "ManyToManyField" || $type == "ForeignKey")) $model_associations[$column] = $data;
            }

            $associations = array_intersect_key($result, $model_associations);
            $values = array_diff_key($result, $associations);
            

            foreach($values as $k => $v){
                if(is_array($v) && !$v) $v = null; // hack for empty xml values that the xml->json->array hack above makes as empty arrays
                if(strtolower($v)=='false') $v=0;
                if(strtolower($v)=='true') $v=1;
                $model->$k = $v;
            }

            if(!$model->save()){
                $this->errors[] = array(
                  "type" => "model validation",
                  "model" => get_class($model),
                  "details" => $model->errors,
                  "data" => $result
                );
                continue;
            }

            foreach($associations as $k => $v){
                if($model->columns[$k][1]["target_model"]){
                    $ret = $this->write_model($v, new $model->columns[$k][1]["target_model"]($this->api_scope));
                    if($model->columns[$k][0] == "ForeignKey") $ret = $ret[0]; //dereference foreign keys by grabbing the first one returned, if multiple are posted on the first will write
                    $model->$k = $ret;
                }
            }

            $rowset[] = $model->row;
        }
        return new WaxRecordset($empty_model, $rowset);
    }
}
