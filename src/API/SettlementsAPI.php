<?php

use Infinex\Exceptions\Error;
use React\Promise;

class SettlementsAPI {
    private $log;
    private $amqp;
    private $settlements;
    private $rewards;
    
    function __construct($log, $amqp, $settlements, $rewards) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> settlements = $settlements;
        $this -> rewards = $rewards;

        $this -> log -> debug('Initialized settlements API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/agg-settlements', [$this, 'getAggSettlements']);
        $rc -> get('/agg-settlements/{year}/{month}', [$this, 'getAggSettlement']);
        $rc -> get('/settlements', [$this, 'getSettlements']);
        $rc -> get('/settlements/{afseid}', [$this, 'getSettlement']);
        $rc -> get('/settlements/{afseid}/rewards', [$this, 'getRewards']);
    }
    
    public function getAggSettlements($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $resp = $this -> settlements -> getAggSettlements([
            'uid' => $auth['uid'],
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        foreach($resp['settlements'] as $k => $v)
            $resp['settlements'][$k] = $this -> ptpAggSettlement($v);
        
        return $resp;
    }
    
    public function getAggSettlement($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> ptpAggSettlement(
            $this -> settlements -> getAggSettlement([
                'uid' => $auth['uid'],
                'year' => $path['year'],
                'month' => $path['month']
            ])
        );
    }
    
    public function getSettlements($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $resp = $this -> settlements -> getSettlements([
            'uid' => $auth['uid'],
            'active' => true,
            'refid' => @$query['refid'],
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        foreach($resp['settlements'] as $k => $v)
            $resp['settlements'][$k] = $this -> ptpSettlement($v);
        
        return $resp;
    }
    
    public function getSettlement($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> ptpSettlement(
            $this -> settlements -> getSettlement([
                'uid' => $auth['uid'],
                'afseid' => $path['afseid'],
                'active' => true
            ])
        );
    }
    
    public function getRewards($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $resp = $this -> rewards -> getRewards([
            'uid' => $auth['uid'],
            'afseid' => $path['afseid'],
            'active' => true
        ]);
        
        $promises = [];
        $mapAssets = [];
        
        foreach($resp['rewards'] as $record) {
            $assetid = $record['assetid'];
            
            if(!array_key_exists($assetid, $mapAssets)) {
                $mapAssets[$assetid] = null;
                
                $promises[] = $this -> amqp -> call(
                    'wallet.wallet',
                    'assetIdToSymbol',
                    [
                        'assetid' => $assetid
                    ]
                ) -> then(
                    function($symbol) use(&$mapAssets, $assetid) {
                        $mapAssets[$assetid] = $symbol;
                    }
                );
            }
        }
        
        return Promise\all($promises) -> then(
            function() use(&$mapAssets, $resp, $th) {
                foreach($resp['rewards'] as $k => $v)
                    $resp['rewards'][$k] = $th -> ptpReward($v, $mapAssets[ $v['assetid'] ]);
                
                return $resp;
            }
        );
    }
    
    private function ptpAggSettlement($record) {
        return [
            'month' => $record['month'],
            'year' => $record['year'],
            'refCoinEquiv' => $record['refCoinEquiv'],
            'acquisition' => $record['acquisition']
        ];
    }
    
    private function ptpSettlement($record) {
        return [
            'afseid' => $record['afseid'],
            'refid' => $record['refid'],
            'month' => $record['month'],
            'year' => $record['year'],
            'refCoinEquiv' => $record['refCoinEquiv'],
            'acquisition' => $record['acquisition']
        ];
    }
    
    private function ptpReward($record, $assetSymbol) {
        return [
            'type' => $record['type'],
            'slaveLevel' => $record['slaveLevel'],
            'amount' => $record['amount'],
            'asset' => $assetSymbol
        ];
    }
}

?>