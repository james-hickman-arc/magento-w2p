<?php
/**
  * Project	magento-w2p
  * Author	Pham Tri Cong <phtcong@gmail.com>
  *  Issue 2: Register a new user
  * 	+ Update Magento DB: add field for UserID (GUID) and a generated password
  *	+ Update session parameters to store UserID and password for unregistered users
  *	+ Send an HTTP GET request to ZP prior to referring the user to any ZP UI.
  *	+ Store user ID and password in user profile or session.
  * Issue 3:
  *	1. Calculate MD5 hash of user password and current user IP address
  *	2. Craft the iframe src URL using TemplateID for the selected product,
  *	UserID, the hash and URL of the page the user will be returned to from ZP
  *	3. Show the IFRAME
  */
class Biinno_Api_Model_W2pUser extends Mage_Api_Model_User
{
	/**
	  *
	  */
    protected function _construct()
    {
        parent::_construct();
		$this->key = $this->getConfigValue("w2p_key");
		$this->base = $this->getConfigValue("w2p_url");
    }
	/**
	  * Process saved order  to magento db
	  * param 	id	order id
	  */
	function order($id){
		$url = $this->getOrderUrl($id);
		$tool = Mage::getModel('api/common');
		
		$datas = $tool->xml2array($url);
		$ret = 1;
		//print_r($datas);
		$product = array();
		if (!isset($datas['OrderDetails'])
			|| !isset($datas['OrderDetails_attr'])
			|| !isset($datas['OrderDetails_attr']['Created'])
			|| !isset($datas['OrderDetails_attr']['ProductName'])
			|| !isset($datas['OrderDetails']['Pages'])
			|| !isset($datas['OrderDetails']['Pages']['Page'])
			
		){
			return -1;
		}
		
		$product['created'] = $tool->strToDate($datas['OrderDetails_attr']['Created']);
		$product['title'] = $datas['OrderDetails_attr']['ProductName'];
		$product['id'] = $id;
		$product['description'] = $datas['OrderDetails_attr']['ProductName'];
		$product['price'] = $datas['OrderDetails_attr']['ProductPrice'] 
		? $datas['OrderDetails_attr']['ProductPrice'] : 0;
		$product['cids'] = 0;
		
		
		$links = "";
		$comma = "";
		if (isset($datas['OrderDetails']['Pages']['Page_attr'])){
			$pages[] = $datas['OrderDetails']['Pages']['Page_attr'];
		}else{
			$pages = $datas['OrderDetails']['Pages']['Page'];
		}
		foreach($pages as $page){
			if (isset($page['PreviewImage'])){
				$links .= $comma . $this->base ."/" .$page['PreviewImage'];
				$comma = ",";
				if (!isset($product['image'])){
					$product['image'] = $this->base ."/" .$page['PreviewImage'];
					$product['thumbnail'] = $this->base ."/" .$page['PreviewImage'];
				} 
			}
		}
		$product['access_url'] = $_SERVER['REQUEST_URI'];
		$product['w2p_image_links'] = $links;
		$data = $tool->saveProduct($product);
		//TODO
		//$this->saveOrder($id);exit();
		//print_r($data);return 1;
		return $data->getId();
	}
	
	/**
	  * Save order  to ZP
	  * param 	id	order id
	  * return array
	  *		ret['pdf'] 	pdf url
	  *		ret['jpg'] 	jpg url
	  *		ret['gif'] 	gif url
	  *		ret['png '] 	png  url
	  *		ret['cdr'] 	cdr url
	  *		
	  */
	function saveOrder($id){
		$url = $this->getSaveOrderUrl($id);
		$tool = Mage::getModel('api/common');
		//echo $url;
		// Send the order id to ZP via HTTP GET
		// If the result is error "ReTry" or communication error repeat the request 2 more times.
		for($i = 0; $i<2; $i++){
			$datas = $tool->xml2array($url);
			if (isset($datas['OrderDetails'])) break;
		}
		$ret = array("pdf"=>""
					,"jpg"=>""
					,"gif"=>""
					,"png"=>""
					,"cdr"=>"");
		if (isset($datas['OrderDetails_attr'])){
			foreach ($ret as $key => $val){
				if (isset($datas['OrderDetails_attr'][strtoupper($key)])){
					$ret[$key] = $this->base . "/" . $datas['OrderDetails_attr'][strtoupper($key)];
				}
			}
		}else{
			Mage::getSingleton('checkout/session')->addError("CAN'T CHANGE STATUS OF $id" );
		}
		Mage::getSingleton('checkout/session')->addError("CHANGE STATUS OF $id :DONE!!!" );
		return $ret;
	}
	
	function getBase(){
		return $this->base;
	}
    function getUserRegisterUrl($url,$key){
		return "$url/API.aspx?page=api-user-new";
	}
	/**
	  * Saved order completion's Feed URL
	  * param 	id	order id
	  */
	function getSaveOrderUrl($id){
		$url = $this->base;
		$key = $this->key;
		return "$url/api.aspx?page=api-order-complete;ApiKey=$key;OrderID=$id";
	}
	/**
	  * Order Detail's Feed URL
	  * param 	id	order id
	  */
	function getOrderUrl($id){
		$url = $this->base;
		$key = $this->key;
		return "$url/api.aspx?page=api-order;ApiKey=$key;OrderID=$id";
	}
	/**
	  * generate w2p user id
	  */
	function generateW2pUserId(){
		return strtoupper($this->uuid());
	}
	/**
	  * generate w2p pass word
	  */
	function generateW2pPassword(){
		return strtoupper(substr(md5(time()),0,6));
	}
	/**
	  * get config value from config_data table
	  * param	 name		name of config data
	  * return	value of the config
	  */
	function getConfigValue($name){
		$config = Mage::getModel('core/config_data');
		$config->load($name, "path");
		
		return $config->getData("config_id")?$config->getValue() : "";
	}
	/**
	  * auto registe user
	  * check if is not registed, this will create new GUID and Pas then registe new user
	  * 
	  */
	function autoRegister(){
		//Mage::getSingleton('core/session')->unsW2puser();
		//Mage::getSingleton('core/session')->unsW2ppass();
		$login = 0;
		//Not have UserId
		if (!$this->key || !$this->base) return ;
		if (!$this->getW2pUserId()){
			$cus = Mage::getSingleton('customer/session')->getCustomer();
			//Check if is created as unregister user			
			if (Mage::getSingleton('core/session')->getW2puser() && $cus->getData('entity_id')){
				//Save to Magento DB
				$cus->setData('w2p_user',Mage::getSingleton('core/session')->getW2puser());
				$cus->setData('w2p_pass',Mage::getSingleton('core/session')->getW2ppass());
				$cus->save();
				$this->state = "registed-u->M";
				//Clear session
				Mage::getSingleton('core/session')->unsW2puser();
				Mage::getSingleton('core/session')->unsW2ppass();
				return 0;
			}
			//Not created, will create new account on ZP
			$this->user = $this->generateW2pUserId();
			$this->pass = $this->generateW2pPassword();
			
			$ret = $this->registerW2pUser($this->user, $this->pass, $this->base, $this->key);
			if ($ret == 1){
				//Save SESSION
				$this->state = "ok";				
				$login = 1;				
				if ($cus->getData('entity_id')){
					//Save to db
					$cus->setData('w2p_user',$this->user);
					$cus->setData('w2p_pass',$this->pass);
					$cus->save();
					$this->state = "ok-m";
				}else{
					//Save to session
					Mage::getSingleton('core/session')->setW2puser($this->user);
					Mage::getSingleton('core/session')->setW2ppass($this->pass);				
				}
			}else{
				//Login Error
				$this->user = "";
				$this->state = "error";
				$login = -1;
			}
			
		}else{			
			$this->state = "registed";
		}
		Mage::getSingleton('core/session')->setState($this->state);
		//Mage::getSingleton('core/session')->unsW2puser();
		//Mage::getSingleton('core/session')->unsW2ppass();
		return $login;
	}
	/**
	  * register user to w2p
	  * param 	user
	  * param 	pass
	  * param 	base
	  * param	key
	  * return 	1 : registe new ok
	  *		0: user is registed
	  *		-1: registe new error
	  */
	function registerW2pUser($user, $pass, $base, $key){		
		$path = "/API.aspx?page=api-user-new";
		$data = array();
		$data['UserID'] = $user;
		$data['Password'] = $pass;
		$data['ApiKey'] = $key;
		
		list($header, $content) = $this->PostRequest($base, $path, $data);
		return $this->xmlParser($content);
	}
	/**
	  * get magneto's role of the user from session
	  */
	function getRole(){
		$cus = Mage::getSingleton('customer/session')->getCustomer();
		return $cus->getData('email')?$cus->getData('email') : "guest";
	}
	/**
	  * get w2p's state of the user from session
	  */
	function getW2pState(){
		return Mage::getSingleton('core/session')->getState();
	}
	/**
	  * get w2p's userId of the user from session
	  */
	function getW2pUserId(){
		$cus = Mage::getSingleton('customer/session')->getCustomer();
		return $cus->getData('entity_id')? $cus->getData('w2p_user') : Mage::getSingleton('core/session')->getW2puser();
	}
	/**
	  * get w2p's pass of the user from session
	  */
	function getW2pPass(){
		$cus = Mage::getSingleton('customer/session')->getCustomer();
		return $cus->getData('entity_id') ? $cus->getData('w2p_pass') : Mage::getSingleton('core/session')->getW2ppass();
	}
	/**
	  * Send Post request
	  * param 	url		url of request
	  * param	path		path of request
	  * param	_data		request data
	  * return	list(header, content)
	  */
	function PostRequest($url, $path, $_data) {
		$referer = $url;
		$data = array();	
		
		while(list($n,$v) = each($_data)){
			$data[] = "$n=$v";
		}	
		$data = implode('&', $data);
		$url = parse_url($url);
		if ($url['scheme'] != 'http') { 
			die('Only HTTP request are supported !');
		}
	 
		$host = $url['host'];
		try{
			$fp = fsockopen($host, 80);			
			fputs($fp, "POST $path HTTP/1.1\r\n");
			fputs($fp, "Host: $host\r\n");
			fputs($fp, "Referer: $referer\r\n");
			fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
			fputs($fp, "Content-length: ". strlen($data) ."\r\n");
			fputs($fp, "Connection: close\r\n\r\n");
			fputs($fp, $data);		 
			$result = ''; 
			while(!feof($fp)) {
				// receive the results of the request
				$result .= fgets($fp, 128);
			}
		 
			fclose($fp);
		 
			$result = explode("\r\n\r\n", $result, 2);
		 
			$header = isset($result[0]) ? $result[0] : '';
			$content = isset($result[1]) ? $result[1] : '';
		 
			return array($header, $content);
		}catch(Exception $e){
			return array("ERROR", "<error/>");
		}
	}
	/**
	  * Generate GUID - UUID
	  * return	UUID
	  */
	function uuid() {   
		return strtoupper(sprintf('%04x%04x-%04x-%03x4-%04x-%04x%04x%04x',
			mt_rand(0, 65535), mt_rand(0, 65535), // 32 bits for "time_low"
			mt_rand(0, 65535), // 16 bits for "time_mid"
			mt_rand(0, 4095),  // 12 bits before the 0100 of (version) 4 for "time_hi_and_version"
			bindec(substr_replace(sprintf('%016b', mt_rand(0, 65535)), '01', 6, 2)),
			mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535) // 48 bits for "node" 
		)); 
	}
	/**
	  * Parser Register User Result ' s XML
	  * param 	content	XML data
	  * return 	ok		if xml is <ok/>
	  *		error		if xml is <error/>
	  */
	function xmlParser($content){
		$ret = "";
		$start = strpos ($content, "<");
		$end = strpos ($content, "/>");
		if (($start !== false) && ($start < $end)){
			$ret = trim(substr($content, $start + 1, $end - $start - 1));
		}
		if ($ret == "ok" ) return 1;
		return -1;
	}
}