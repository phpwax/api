<?php
class DataPush{
  public $url = false;
  public $post_data = false;
  public $post_format = "xml";
  public $username = false;
  public $password = false;
  
  public $response = false;
  public $request_info = false;
  
  function __construct($data=array()){
    foreach((array)$data as $k => $v) $this->$k = $v;
    
    //support for providing post data as a model/recordset, conversion functions will run if they're defined
    foreach(array("WaxModel", "WaxRecordSet") as $source_base_class){
      if($this->post_data instanceof $source_base_class){
        $conversion_method = "convert_".$source_base_class;
        if(method_exists($this, $conversion_method)) $this->$conversion_method();
        break;
      }
    }
  }
  
  public function request(){
    $session = curl_init($this->url);
    
    curl_setopt($session, CURLOPT_TIMEOUT, 60);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($session, CURLOPT_FOLLOWLOCATION, 1);
    
    if($this->post_data){
      curl_setopt($session, CURLOPT_POST, 1);
      curl_setopt($session, CURLOPT_POSTFIELDS, $this->post_data);
    }
    
    if($this->username && $this->password) curl_setopt($session, CURLOPT_USERPWD, $this->username.':'.$this->password);
    
    $this->reponse = curl_exec($session);
    $this->request_info = curl_getinfo($session);
    
    curl_close($session);
    
    if($info['http_code'] != 200) WaxLog::log("error", "DataPush error. Response:\n".print_r($this->response, 1)."\nCurl Info:\n".print_r($this->request_info, 1));
    
    return array("response"=>$this->response, "info"=>$this->request_info);
  }
  
  private function render_view($data = array(), $use_view = "data_push", $use_layout = "application"){
    $view = new WaxTemplate($data);
    
    foreach(Autoloader::view_paths("user") as $path) {
      $view->add_path($path."DataPush/$use_view");
      $view->add_path($path."shared/$use_view");
      $view->add_path($path.$use_view);
    }
    
    $view->add_path(PLUGIN_DIR."api/view/DataPush/$use_view");
    $view->add_path(PLUGIN_DIR."api/view/shared/$use_view");
    
    $content = $view->parse($this->post_format, 'views');
    
    $layout = new WaxTemplate(array_merge($data, array("content_for_layout"=>$content)));
    
    $layout->add_path(VIEW_DIR."layouts/$use_layout");
    $layout->add_path(PLUGIN_DIR."api/view/layouts/$use_layout");
    
    return $layout->parse($this->post_format);
  }
  
  //convert WaxRecordset to target format
  public function convert_WaxRecordset(){
    if($ret = $this->render_view(array("recordset"=>$this->post_data))) $this->post_data = $ret;
  }
  
  //convert WaxModel to target format
  public function convert_WaxModel(){
    $this->post_data = new WaxRecordset($this->post_data, array($this->post_data->row));
    $this->convert_WaxRecordset();
  }
  
}
?>