<?php

use Infinex\Exceptions\Error;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;

class Rewards {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;

        $this -> log -> debug('Initialized rewards manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getRewards',
            [$this, 'getRewards']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started rewards manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start rewards manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getRewards');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped rewards manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop rewards manager: '.((string) $e));
            }
        );
    }
    
    public function getRewards($body) {
        if(!isset($body['afseid']))
            throw new Error('MISSING_DATA', 'afseid', 400);
        
        if(!validateId($body['afseid']))
            throw new Error('VALIDATION_ERROR', 'afseid', 400);
        
        // Get rewards
        $task = [
            ':afseid' => $body['afseid']
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
        
        while($row = $q -> fetch()) {
            $rewards[] = $this -> rtrReward($row);
        }
        
        return [
            'rewards' => $rewards
        ];
    }
    
    private function rtrReward($row) {
        return [
            'type' => $row['reward_type'],
            'slaveLevel' => $row['slave_level'],
            'amount' => trimFloat($row['reward']),
            'assetid' => $row['assetid']
        ];
    }
}

?>