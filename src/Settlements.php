<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Math\trimFloat;
use React\Promise;

class SettlementsAPI {
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
            'getReflinks',
            [$this, 'getReflinks']
        );
        
        $promises[] = $this -> amqp -> method(
            'getReflink',
            [$this, 'getReflink']
        );
        
        $promises[] = $this -> amqp -> method(
            'deleteReflink',
            [$this, 'deleteReflink']
        );
        
        $promises[] = $this -> amqp -> method(
            'createReflink',
            [$this, 'createReflink']
        );
        
        $promises[] = $this -> amqp -> method(
            'editReflink',
            [$this, 'editReflink']
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
        
        $promises[] = $this -> amqp -> unreg('getReflinks');
        $promises[] = $this -> amqp -> unreg('getReflink');
        $promises[] = $this -> amqp -> unreg('deleteReflink');
        $promises[] = $this -> amqp -> unreg('createReflink');
        $promises[] = $this -> amqp -> unreg('editReflink');
        
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
    
    public function getAggSettlements($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(isset($query['refid']))
            $this -> validateAndCheckRefid($query['refid'], $auth['uid']);
            
        $pag = new Pagination\Offset(50, 500, $query);
        
        $task = array(
            ':uid' => $auth['uid']
        );
        if(isset($query['refid']))
            $task[':refid'] = $query['refid'];
        
        $sql = 'SELECT extract(month from affiliate_settlements.month) as month_human,
	               extract(year from affiliate_settlements.month) as year,
	               affiliate_settlements.month,
                   SUM(affiliate_settlements.mastercoin_equiv) AS mastercoin_equiv
           FROM affiliate_settlements,
                reflinks
           WHERE affiliate_settlements.refid = reflinks.refid
           AND reflinks.uid = :uid';
    
        if(isset($query['refid']))
            $sql .= ' AND reflinks.refid = :refid';
        
        $sql .= ' GROUP BY affiliate_settlements.month
                  ORDER BY affiliate_settlements.month DESC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $settlements = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            
            $settlements[] = [
                'month' => $row['month_human'],
                'year' => $row['year'],
                'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
                'acquisition' => $this -> getAggAcquisition(
                    $auth['uid'],
                    $row['month']
                )
            ];
        }
        
        return [
            'settlements' => $settlements,
            'more' => $pag -> more,
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getAggSettlement($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!$this -> validateYear($path['year']))
            throw new Error('VALIDATION_ERROR', 'year');
        if(!$this -> validateMonth($path['month']))
            throw new Error('VALIDATION_ERROR', 'month');
        
        $task = array(
            ':uid' => $auth['uid'],
            ':year' => $path['year'],
            ':month' => $path['month']
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
            throw new Error('NOT_FOUND', 'No settlement for '.$path['month'].'/'.$path['year'], 404);
        
        return [
            'month' => $row['month_human'],
            'year' => $row['year'],
            'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
            'acquisition' => $this -> getAggAcquisition(
                $auth['uid'],
                $row['month']
            ),
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getSettlementsOfReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $this -> validateAndCheckRefid($path['refid'], $auth['uid']);
            
        $pag = new Pagination\Offset(50, 500, $query);
        
        $task = array(
            ':refid' => $path['refid']
        );
        
        $sql = 'SELECT afseid,
                       extract(month from month) as month_human,
	                   extract(year from month) as year,
	                   month,
                       mastercoin_equiv
                FROM affiliate_settlements
                WHERE refid = :refid
                ORDER BY month DESC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $settlements = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            
            $settlements[] = [
                'afseid' => $row['afseid'],
                'month' => $row['month_human'],
                'year' => $row['year'],
                'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
                'acquisition' => $this -> getAcquisition($row['afseid'])
            ];
        }
        
        return [
            'settlements' => $settlements,
            'more' => $pag -> more,
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getSettlementOfReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $this -> validateAndCheckRefid($path['refid'], $auth['uid']);
        if(!$this -> validateAfseid($path['afseid']))
            throw new Error('VALIDATION_ERROR', 'afseid');
        
        $task = array(
            ':refid' => $path['refid'],
            ':afseid' => $path['afseid']
        );
        
        $sql = 'SELECT afseid,
                       extract(month from month) as month_human,
	                   extract(year from month) as year,
	                   month,
                       mastercoin_equiv
                FROM affiliate_settlements
                WHERE refid = :refid
                AND afseid = :afseid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Settlement '.$path['afseid'].' not found', 404);
        
        return [
            'afseid' => $path['afseid'],
            'month' => $row['month_human'],
            'year' => $row['year'],
            'refCoinEquiv' => trimFloat($row['mastercoin_equiv']),
            'acquisition' => $this -> getAcquisition($path['afseid']),
            'refCoin' => $this -> refCoin
        ];
    }
    
    public function getRewards($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $this -> validateAndCheckRefid($path['refid'], $auth['uid']);
        if(!$this -> validateAfseid($path['afseid']))
            throw new Error('VALIDATION_ERROR', 'afseid');
            
        // Check settlements exists
        $task = array(
            ':refid' => $path['refid'],
            ':afseid' => $path['afseid']
        );
        
        $sql = 'SELECT 1
                FROM affiliate_settlements
           WHERE refid = :refid
           AND afseid = :afseid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Settlement '.$path['afseid'].' not found', 404);
        
        // Get rewards
        $task = [
            ':afseid' => $path['afseid']
        ];
        
        $sql = 'SELECT slave_level,
				       reward,
				       assetid,
				       reward_type
		        FROM affiliate_rewards
                WHERE afseid = :afseid
                ORDER BY reward_type ASC,
                         slave_level ASC,
                         assetid ASC';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
    
        $rewards = [];
        $assetSymbols = [];
        
        while($row = $q -> fetch()) {
            $rewards[] = [
                'type' => $row['reward_type'],
                'slaveLevel' => $row['slave_level'],
                'amount' => trimFloat($row['reward'])
            ];
            
            $assetSymbols[] = $row['assetid'];
        }
        
        $promises = [];
        
        foreach($assetSymbols as $k => $v)
            $promises[] = $this -> amqp -> call(
                'wallet.wallet',
                'assetIdToSymbol',
                [
                    'symbol' => $v
                ]
            ) -> then(
                function($data) use(&$rewards, $k) {
                    $rewards[$k]['asset'] = $data['symbol'];
                }
            );
        
        return Promise\all($promises) -> then(
            function() use(&$rewards) {
                return [
                    'rewards' => $rewards
                ];
            }
        );
    }
    
    private function validateAndCheckRefid($refid, $uid) {
        if(!validateRefid($refid))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
            
        $task = [
            ':uid' => $uid,
            ':refid' => $refid
        ];
        
        $sql = 'SELECT 1
                FROM reflinks
                WHERE refid = :refid
                AND uid = :uid
                AND active = TRUE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
    
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$refid.' not found');
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
}

?>