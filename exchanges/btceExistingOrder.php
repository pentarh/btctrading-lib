<?php

class btceExistingOrder {
    protected $btce;
    
    public $oid;
    public $pair;
    // type: buy, sell
    public $type;
    public $amount;
    public $rate;
    // status:
    // 0 - active
    // 1 - executed
    // 2 - canceled
    // 3 - partially executed
    public $status;
    public $date;
    
    public function __construct(&$order,&$btce) {
	$this->btce=$btce;
	$this->oid=$order['id'];
	$this->pair=$order['pair'];
	$this->type=$order['type'];
	$this->rate=$order['rate'];
	$this->amount=$order['amount'];
	$this->status=$order['status'];
	$this->date=$order['timestamp_created'];
    }
    
    public function cancel() {
	$this->btce->cancelOrder($this->oid);
    }
}
