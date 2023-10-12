<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;
use React\Promise;

class Affiliations {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;

        $this -> log -> debug('Initialized affiliations manager');
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
                $th -> log -> info('Started affiliations manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start affiliations manager: '.((string) $e));
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
                $th -> log -> info('Stopped affiliations manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop affiliations manager: '.((string) $e));
            }
        );
    }
    
    public function getRewards($body) {
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
}

?>