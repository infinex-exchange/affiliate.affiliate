<?php

use Infinex\Exceptions\Error;

class SettlementsAPI {
    private $log;
    private $settlements;
    private $reflinks;
    private $rewards;
    
    function __construct($log, $settlements, $reflinks, $rewards) {
        $this -> log = $log;
        $this -> settlements = $settlements;
        $this -> reflinks = $reflinks;
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
        
        if(isset($query['refid'])) {
            $reflink = $this -> reflinks -> getReflink([
                'refid' => $query['refid']
            ]);
        
            if($reflink['uid'] != $auth['uid'])
                throw new Error('FORBIDDEN', 'No permissions to reflink '.$query['refid'], 403);
            
            $refid = $query['refid'];
            $uid = null;
        }
        else {
            $refid = null;
            $uid = $auth['uid'];
        }
        
        $resp = $this -> settlements -> getSettlements([
            'uid' => $uid,
            'refid' => $refid,
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
        
        $set = $this -> settlements -> getSettlement([
            'afseid' => $path['afseid'],
        ]);
        
        if($set['uid'] != $auth['uid'])
            throw new Error('FORBIDDEN', 'No permissions to settlement '.$path['afseid'], 403);
        
        return $this -> ptpSettlement($set);
    }
    
    public function getRewards($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $set = $this -> settlements -> getSettlement([
            'afseid' => $path['afseid'],
        ]);
        
        if($set['uid'] != $auth['uid'])
            throw new Error('FORBIDDEN', 'No permissions to settlement '.$path['afseid'], 403);
        
        $resp = $this -> rewards -> getRewards([
            'afseid' => $path['afseid']
        ]);
        
        foreach($resp['rewards'] as $k => $v)
            $resp['rewards'][$k] = $th -> ptpReward($v);
        
        return $resp;
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
    
    private function ptpReward($record) {
        return [
            'type' => $record['type'],
            'slaveLevel' => $record['slaveLevel'],
            'amount' => $record['amount'],
            'assetid' => $record['assetid']
        ];
    }
}

?>