<?php

class btceNewOrder {
    protected $btce;
    
    public $order;
    public $type;
    public $amount;
    public $rate;
    
    public $result_btc;
    public $result_usd;
    public $result_rate;

    public function __construct(&$btce) {
	$this->btce=&$btce;
	$this->init();
    }
    
    protected function init() {
	$this->order = null;
	$this->result_btc=0;
	$this->result_usd=0;
	$this->result_rate=-1;    
    }

    public function buy($amount,$rate) {
	$this->init();
	$this->type='buy';
	$this->amount=$amount;
	$this->rate=$rate;
	$this->btce->getInfo();
	$result=$this->btce->submitOrder($this->type,$this->amount,$this->rate);
	if ($result['order_id']) {
	    $this->order=$this->btce->findOrder($result['order_id']);
	}
	if ($result['received'] > 0) {
	    $this->result_btc=$result['funds']['btc'] - $this->btce->info['btc'];
	    $this->result_usd=$this->btce->info['usd'] - $result['funds']['usd'];
	    if ($this->result_btc > 0) {
		$this->result_rate=$this->result_usd / $this->result_btc;
	    }
	}
    }

    public function sell($amount,$rate) {
	$this->init();
	$this->type='sell';
	$this->amount=$amount;
	$this->rate=$rate;
	$this->btce->getInfo();
	$result=$this->btce->submitOrder($this->type,$this->amount,$this->rate);
	if ($result['order_id']) {
	    $this->order=$this->btce->findOrder($result['order_id']);
	}
	if ($result['received'] > 0) {
	    $this->result_btc=$this->btce->info['btc'] - $result['funds']['btc'];
	    $this->result_usd=$result['funds']['usd'] - $this->btce->info['usd'];
	    if ($this->result_btc > 0) {
		$this->result_rate=$this->result_usd / $this->result_btc;
	    }
	}
    }
    
    public function buyAll() {
	// Buy coins with all available USD balance!!!
	$this->btce->getInfo();
	if ($this->btce->info['usd'] < MINBALANCE_USD) {
	    throw new Exception("buyAll(): Not in cash!");
	}
	
	$this->marketBuy($this->btce->info['usd']);
	if ($this->order) {
	    $this->order->cancel();
	}
    }
    
    public function sellAll() {
	// Sell all coins!!!
	$this->btce->getInfo();
	if ($this->btce->info['btc'] < MINBALANCE_BTC) {
	    throw new Exception("sellAll(): Not in BTC!");
	}
	$this->marketSell($this->btce->info['btc']);
	if ($this->order) {
	    $this->order->cancel();
	}
    }
    
    public function marketBuy($usd) {
	$result=$this->btce->estimateDepthUSD($usd*0.999);
	$btc_to_buy=round($result['btc'],3);
	$result=$this->btce->estimateDepth('buy',$btc_to_buy);
	$head=$result['last_rate']*1.1;
	$this->buy($btc_to_buy,$head);
	return;
    
    }

    public function marketSell($btc) {
	$result=$this->btce->estimateDepth('sell',$btc*0.999);
	$floor=$result['last_rate']*0.9;
	$this->sell($btc,$floor);
	return;
    }    
}
