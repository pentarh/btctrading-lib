<?php

class btce {
    protected $key;
    protected $secret;
    protected $nonce;
    
    public $pair;
    public $info;
    public $ticker;
    
    public $retry_count;
    public $retry_wait;
    
    public function __construct($key, $secret, $pair='btc_usd') {
	if (isset($secret) && isset($key)) {
	    $this->key = $key;
	    $this->secret = $secret;
	    $this->nonce = time();
	    $this->pair=$pair;
	    $this->retry_count=10;
	    $this->retry_wait=30;
	    return;
	}
	throw new Exception("No key/secret provided");
    }
    
    protected function _exec($getpost,$url,$headers=array(),$post_data='') {
        static $ch = null; 
        if (is_null($ch)) { 
    	    $ch = curl_init(); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; BTCE PHP client; '.php_uname('s').'; PHP/'.phpversion().')'); 
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($getpost=='post') { 
    	    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data); 
    	    curl_setopt($ch, CURLOPT_POST, true);
    	} else {
    	    curl_setopt($ch, CURLOPT_HTTPGET, true);
    	}
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
        $res = curl_exec($ch); 
        if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch)); 
        $dec = json_decode($res, true); 
        if (!is_array($dec)) {
    	    var_export($res);
    	    throw new Exception('Invalid data received, please make sure connection is working and requested API exists'); 
    	}
        return $dec;
    }
    
    protected function _query_private($method, array $req = array()) { 
        $req['nonce'] = ++$this->nonce; 
        $req['method'] = $method;
        $post_data = http_build_query($req, '', '&'); 
        $headers = array( 
    	    'Key: '.$this->key, 
            'Sign: '.hash_hmac('sha512', $post_data, $this->secret), 
	);
	$okay=false;
	$tries=0;
	do {
	    try {
		$res=$this->_exec('post','https://btc-e.com/tapi',$headers,$post_data);
		$okay=true;
	    } catch (Exception $e) {
		trigger_error("Got error '".$e->getMessage()."', wait for $tries retry",E_USER_WARNING);
		sleep($this->retry_wait);
	    }
	} while(!$okay && ++$tries<=$this->retry_count);
	if (!$okay) {
	    throw new Exception("Request failed after $tries tries. Last error: ".$e->getMessage());
	}
	
	if (!isset($res['success'])) {
	    throw new Exception('BTCE didnt return success'); 
	}
	if ($res['success']!=1) {
	    if (isset($res['error'])) {
		throw new Exception('BTCE error: '.$res['error']);
	    } else {
		throw new Exception('BTCE not success w/o description');
	    } 
	}
	return $res;
    }
    
    protected function _query_public($method) {
	$okay=false;
	$tries=0;
	do {
	    try {
		$res=$this->_exec('get','https://btc-e.com/api/'.$method);
		$okay=true;
	    } catch (Exception $e) {
		trigger_error("Got error '".$e->getMessage()."', wait for $tries retry",E_USER_WARNING);
		sleep($this->retry_wait);
	    }
	} while(!$okay && ++$tries<=$this->retry_count);
	if (!$okay) {
	    throw new Exception("Request failed after $tries tries. Last error: ".$e->getMessage());
	}
	return $res;
    }

    
    public function getTicker() {
	$result=$this->_query_public('2/'.$this->pair.'/ticker');
	if (!is_array($result['ticker'])) {
	    throw new Exception("getTicker(): Result wasn't success");
	}
	$this->ticker=array(
	    'high'=>$result['ticker']['high'],
	    'low'=>$result['ticker']['low'],
	    'avg'=>$result['ticker']['avg'],
	    'vol'=>$result['ticker']['vol_cur'],
	    'last'=>$result['ticker']['last'],
	    'buy'=>$result['ticker']['buy'],
	    'sell'=>$result['ticker']['sell']
	);
    }
    
    public function getInfo() {
	$result=$this->_query_private('getInfo');
	
	$this->info=array(
	    'btc' => $result['return']['funds']['btc'],
	    'usd' => $result['return']['funds']['usd']
	);
	
	$result=$this->_query_public('2/'.$this->pair.'/fee');
	
	if (!isset($result['trade'])) {
	    throw new Exception('getFee wasnt success');
	}
	
	$this->info['fee']=$result['trade'];
    }
    
    // Return array of btceExistingOrder instances    
    public function getOrders($params=array()) {
	$params['pair']=$this->pair;
	$result=$this->_query_private('OrderList',$params);
	if (!is_array($result['return'])) {
	    throw new Exception("getOrders(): no return");
	}
	$orders=array();
	foreach ($result['return'] as $order_id => $order_info) {
	    $order_info['id']=$order_id;
	    $orders[]=new btceExistingOrder($order_info,$this);
	}
	return $orders;
    }
    
    // Find order by id, return btceExistingOrder instance or false
    public function findOrder($oid) {
	$orders=$this->getOrders();
	foreach ($orders as $order) {
	    if ($oid == $order->oid) {
		return $order;
	    }
	}
	return false;
    }
    
    // Cancel order by id
    public function cancelOrder($oid) {
	$params=array(
	    'order_id'=>$oid
	);
	$result=$this->_query_private('CancelOrder',$params);
    }
    
    // Make new order
    public function submitOrder($type,$amount,$rate) {
	if ($type != 'buy' && $type != 'sell') {
	    throw new Exception("submitOrder(): invalid type given: '$type'");
	}
	$params=array(
	    'pair'=>$this->pair,
	    'type'=>$type,
	    'amount'=>sprintf('%.2F',$amount),
	    'rate'=>sprintf('%.2F',$rate)
	);
	$result=$this->_query_private('Trade',$params);
	if (!isset($result['return'])) {
	    throw new Exception("submitOrder(): Result wasn't success");
	}
	return $result['return'];
    }
    
    // Cancel orders by type: buy, sell, all
    public function massCancel($type) {
	$orders=$this->getOrders();
	foreach($orders as $order) {
	    if ($type == $order->type || $type== 'all') $order->cancel();
	}
    }
    
    // Get market depth
    public function getDepth() {
	return $this->_query_public('2/'.$this->pair.'/depth');
    }
    
    
    // Get depth statistics
    public function getDepthSummary() {
	$a=$this->getDepth();
	$ret=array(
	    'bids'=>array(),
	    'asks'=>array(),
	);
	
	foreach(array('bids','asks') as $type) {
	    $a1=$a[$type];
	    $ret[$type]['min_rate']=99999999;
	    $ret[$type]['max_rate']=0;
	    $ret[$type]['avg_rate']=0;
	    $ret[$type]['total_usd']=0;
	    $ret[$type]['total_btc']=0;
	    foreach ($a1 as $order) {
		list($rate,$amount) = $order;
		if ($rate < $ret[$type]['min_rate']) $ret[$type]['min_rate']=$rate;
		if ($rate > $ret[$type]['max_rate']) $ret[$type]['max_rate']=$rate;
		$ret[$type]['total_btc']+=$amount;
		$ret[$type]['total_usd']+=$amount*$rate;
	    }
	    $ret[$type]['avg_rate']=$ret[$type]['total_usd']/$ret[$type]['total_btc'];
	}
	
	return $ret;
    }
    
    // Get average rate of order amount
    public function estimateDepth($type,$amount) {
	if ($type=='buy') $type='asks';
	if ($type=='sell') $type='bids';
	if ($amount <= 0) throw new Exception("What?");
	$a=$this->getDepth();
	$a1=$a[$type];
	$testamount=$amount;
	$totalusd=0;
	foreach($a1 as $order) {
	    list($rate,$amt)=$order;
	    if ($amt <= $testamount) {
		$testamount-=$amt;
		$totalusd+=$amt*$rate;
	    } else {
		$totalusd+=$testamount*$rate;
		$testamount=0;
	    }
	    if ($testamount <=0) break;
	}
	return array(
	    'last_rate' => $rate,
	    'usd' => $totalusd,
	    'avg_rate' => $totalusd / $amount,
	);
    }
    
    // How much BTC will get for usd
    public function estimateDepthUSD($usd) {
	$a=$this->getDepth();
	$testusd=$usd;
	$totalbtc=0;
	foreach($a['asks'] as $order) {
	    list($rate,$amt)=$order;
	    if ($rate*$amt <= $testusd) {
		$testusd-=$rate*$amt;
		$totalbtc+=$amt;
	    } else {
		$totalbtc+=$testusd/$rate;
		$testusd=0;
	    }
	    if ($testusd <=0) break;
	}
	
	return array(
	    'last_rate' => $rate,
	    'btc' => $totalbtc,
	    'avg_rate' => $usd/$totalbtc,
	);
    }
    
    // Return % of depth shift after executing order
    public function estimateDepthShift($type,$amount) {
	$this->getTicker();
	$start=$type=='buy'?$this->ticker['sell']:$this->ticker['buy'];
	$estimate=$this->estimateDepth($type,$amount);
	return round(abs($start-$estimate['avg_rate'])*100/$start,2);
    }
}



