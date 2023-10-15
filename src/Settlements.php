<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Math\trimFloat;
use React\Promise;

class Settlements {
    private $log;
    private $amqp;
    private $pdo;
    private $reflinks;
    private $refCoin;
    
    function __construct($log, $amqp, $pdo, $reflinks, $refCoin) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> reflinks = $reflinks;
        $this -> refCoin = $refCoin;

        $this -> log -> debug('Initialized settlements manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getAggSettlements',
            [$this, 'getAggSettlements']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAggSettlement',
            [$this, 'getAggSettlement']
        );
        
        $promises[] = $this -> amqp -> method(
            'getSettlements',
            [$this, 'getSettlements']
        );
        
        $promises[] = $this -> amqp -> method(
            'getSettlement',
            [$this, 'getSettlement']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started settlements manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start settlements manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getAggSettlements');
        $promises[] = $this -> amqp -> unreg('getAggSettlement');
        $promises[] = $this -> amqp -> unreg('getSettlements');
        $promises[] = $this -> amqp -> unreg('getSettlement');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped settlements manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop settlements manager: '.((string) $e));
            }
        );
    }
    
    public function getAggSettlements($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid', 400);
            
        $pag = new Pagination\Offset(50, 500, $body);
        
        $task = array(
            ':uid' => $body['uid']
        );
        
        $sql = 'SELECT extract(month from affiliate_settlements.month) as month_human,
	               extract(year from affiliate_settlements.month) as year,
	               affiliate_settlements.month,
                   SUM(affiliate_settlements.mastercoin_equiv) AS mastercoin_equiv
           FROM affiliate_settlements,
                reflinks
           WHERE affiliate_settlements.refid = reflinks.refid
           AND reflinks.uid = :uid';
        
        $sql .= ' GROUP BY affiliate_settlements.month
                  ORDER BY affiliate_settlements.month DESC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $settlements = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $settlements[] = $this -> rtrAggSettlement($row, $body['uid']);
        }
        
        return [
            'settlements' => $settlements,
            'more' => $pag -> more,
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getAggSettlement($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid', 400);
        if(!isset($body['year']))
            throw new Error('MISSING_DATA', 'year', 400);
        if(!isset($body['month']))
            throw new Error('MISSING_DATA', 'month', 400);
        
        if(!$this -> validateYear($body['year']))
            throw new Error('VALIDATION_ERROR', 'year');
        if(!$this -> validateMonth($body['month']))
            throw new Error('VALIDATION_ERROR', 'month');
        
        $task = array(
            ':uid' => $body['uid'],
            ':year' => $body['year'],
            ':month' => $body['month']
        );
        
        $sql = 'SELECT extract(month from affiliate_settlements.month) as month_human,
	               extract(year from affiliate_settlements.month) as year,
	               affiliate_settlements.month,
                   SUM(affiliate_settlements.mastercoin_equiv) AS mastercoin_equiv
                FROM affiliate_settlements,
                     reflinks
                WHERE affiliate_settlements.refid = reflinks.refid
                AND reflinks.uid = :uid
                AND EXTRACT(month FROM affiliate_settlements.month) = :month
                AND EXTRACT(year FROM affiliate_settlements.month) = :year';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'No settlement for '.$body['month'].'/'.$body['year'], 404);
        
        $resp = $this -> rtrAggSettlement($row, $body['uid']);
        $resp['refCoin'] = $this -> refCoin;
        return $resp;
    }
    
    public function getSettlements($body) {
        if(isset($body['active']) && !is_bool($body['active']))
            throw new Error('VALIDATION_ERROR', 'active', 400);
        
        if(isset($body['refid']))
            $this -> reflinks -> getReflink([
                'refid' => $body['refid'],
                'uid' => @$body['uid'],
                'active' => @$body['active']
            ]);
            
        $pag = new Pagination\Offset(50, 500, $body);
        
        $task = [];
        
        $sql = 'SELECT affiliate_settlements.afseid,
                       affiliate_settlements.refid,
                       extract(month from affiliate_settlements.month) as month_human,
    	               extract(year from affiliate_settlements.month) as year,
    	               affiliate_settlements.month,
                       affiliate_settlements.mastercoin_equiv
                FROM affiliate_settlements';
        
        if(isset($body['refid'])) {
            $task[':refid'] = $body['refid'];
            $sql .= ' WHERE affiliate_settlements.refid = :refid';
        }
        else if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ', reflinks
                     WHERE reflinks.refid = affiliate_settlements.refid
                     AND reflinks.uid = :uid';
            if(isset($body['active'])) {
                $task[':active'] = $body['active'] ? 1 : 0;
                $sql .= ' AND reflinks.active = :active';
            }
        }
        
        $sql .= ' ORDER BY affiliate_settlements.afseid DESC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $settlements = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $settlements[] = $this -> rtrSettlement($row);
        }
        
        return [
            'settlements' => $settlements,
            'more' => $pag -> more,
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getSettlement($body) {
        if(!isset($body['afseid']))
            throw new Error('MISSING_DATA', 'afseid', 400);
        
        if(!$this -> validateAfseid($body['afseid']))
            throw new Error('VALIDATION_ERROR', 'afseid', 400);
            
        if(isset($body['active']) && !is_bool($body['active']))
            throw new Error('VALIDATION_ERROR', 'active', 400);
        
        $task = [
            ':afseid' => $body['afseid']
        ];
        
        $sql = 'SELECT affiliate_settlements.afseid,
                       affiliate_settlements.refid,
                       extract(month from affiliate_settlements.month) as month_human,
    	               extract(year from affiliate_settlements.month) as year,
    	               affiliate_settlements.month,
                       affiliate_settlements.mastercoin_equiv
                FROM affiliate_settlements';
        
        if(isset($body['uid']) || isset($body['active']))
            $sql .= ', reflinks WHERE reflinks.refid = affiliate_settlements.refid';
        else
            $sql .= ' WHERE 1=1';
        
        $sql .= ' AND affiliate_settlements.afseid = :afseid';
            
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND reflinks.uid = :uid';
        }
        
        if(isset($body['active'])) {
            $task[':active'] = $body['active'] ? 1 : 0;
            $sql .= ' AND reflinks.active = :active';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Settlement '.$body['afseid'].' not found', 404);
        
        $resp = $this -> rtrSettlement($row);
        $resp['refCoin'] = $this -> refCoin;
        return $resp;
    }
    
    private function getAggAcquisition($uid, $month) {
        $task = [
            ':uid' => $uid,
            ':month' => $month
        ];
            
        $sql = 'SELECT affiliate_slaves_snap.slave_level,
                       SUM(affiliate_slaves_snap.slaves_count) AS slaves_count
                FROM affiliate_slaves_snap,
                     affiliate_settlements,
                     reflinks
                WHERE affiliate_slaves_snap.afseid = affiliate_settlements.afseid
                AND affiliate_settlements.month = :month
                AND affiliate_settlements.refid = reflinks.refid
                AND reflinks.uid = :uid
                GROUP BY affiliate_slaves_snap.slave_level
	            ORDER BY affiliate_slaves_snap.slave_level ASC';
	    
	    $q = $this -> pdo -> prepare($sql);
	    $q -> execute($task);
	    
        $result = [];
        
	    while($row = $q -> fetch())
		    $result[ $row['slave_level'] ] = $row['slaves_count'];
        
        return $result;
    }
    
    private function getAcquisition($afseid) {
        $task = [
            ':afseid' => $afseid
        ];
            
        $sql = 'SELECT slave_level,
                       slaves_count
                FROM affiliate_slaves_snap
                WHERE afseid = :afseid
	            ORDER BY slave_level ASC';
	    
	    $q = $this -> pdo -> prepare($sql);
	    $q -> execute($task);
	    
        $result = [];
        
	    while($row = $q -> fetch())
		    $result[ $row['slave_level'] ] = $row['slaves_count'];
        
        return $result;
    }
    
    private function validateYear($year) {
        if(!is_int($year)) return false;
        if($year < 2020) return false;
        if($year > 9999) return false;
        return true;
    }
    
    private function validateMonth($month) {
        if(!is_int($month)) return false;
        if($month < 1) return false;
        if($month > 12) return false;
        return true;
    }
    
    private function validateAfseid($afseid) {
        if(!is_int($afseid)) return false;
        if($afseid < 1) return false;
        return true;
    }
    
    private function rtrAggSettlement($row, $uid) {
        return [
            'month' => $row['month_human'],
            'year' => $row['year'],
            'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
            'acquisition' => $this -> getAggAcquisition(
                $uid,
                $row['month']
            )
        ];
    }
    
    private function rtrSettlement($row) {
        return [
            'afseid' => $row['afseid'],
            'refid' => $row['refid'],
            'month' => $row['month_human'],
            'year' => $row['year'],
            'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
            'acquisition' => $this -> getAcquisition($row['afseid'])
        ];
    }
}

?>