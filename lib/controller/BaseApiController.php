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

  public function controller_global(){
    if($header = $this->header_types[$this->use_format]) header("Content-Type: $header");
    if(!in_array($this->action, $this->allowed_models)) throw new WXRoutingException('The model you are looking for is not available', "Model not found", '404');
    elseif($this->model_class = Inflections::camelize($this->action, true)) $this->model = new $this->model_class;
    else throw new WXRoutingException('Error', "Model Error", '404');

    //set format
    if(!$this->use_format) $this->use_format = $this->allowed_formats[0];
    //set view - use cms view
    $this->action = "process_api_request";
    $this->use_view = "process_api_request";
  }

  //from the model find and spit out all data
  public function process_api_request(){
    $this->model = $this->model->scope($this->api_scope);
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

  public function filter_has_many($model, $col, $values){
    if(!is_array($values)) $values = array($values);
    $target_class = $model->columns[$col][1]['target_model'];
    $target = new $target_class;
    $model_join_col = ($model->columns[$col][1]['join_field']) ? $model->columns[$col][1]['join_field'] : $model->table."_".$model->primary_key;
    $ids = array(0);
    foreach($target->filter($model_join_col, $values)->all() as $row) $ids[] = $row->primval;
    return $model->filter($model->primary_key, $ids);
  }
}
?>