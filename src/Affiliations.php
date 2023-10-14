<?php

use Infinex\Exceptions\Error;
use React\Promise;

class Affiliations {
    private $log;
    private $amqp;
    private $pdo;
    private $reflinks;
    
    function __construct($log, $amqp, $pdo, $reflinks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> reflinks = $reflinks;

        $this -> log -> debug('Initialized affiliations manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> sub(
            'registerUser',
            [$this, 'setupAccount'],
            'affiliate_signup',
            true,
            [
                'affiliation' => true
            ]
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
        
        $promises[] = $this -> amqp -> unsub('affiliate_signup');
        
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
    
    public function setupAccount($body) {
        if(!isset($body['uid'])) {
            $this -> log -> error('registerUser without uid');
            return;
        }
        
        if(!isset($body['refid'])) {
            $this -> log -> error('registerUser without refid');
            return;
        }
        
        try {
            $reflink = $this -> reflinks -> getReflink([
                'refid' => $body['refid']
            ]);
        }
        catch(Error $e) {
            $this -> log -> warn(
                'Not registered affiliation uid='.$body['uid'].' to reflink='.$body['refid'].
                ': '.((string) $e)
            );
            return;
        }
        
        $this -> pdo -> beginTransaction();
        
        if($refid['active']) {
            $task = array(
                ':refid' => $body['refid'],
                ':slave_uid' => $body['uid']
            );
            
            $sql = 'INSERT INTO affiliations(
                        refid,
                        slave_uid,
                        slave_level
                    )
                    VALUES(
                        :refid,
                        :slave_uid,
                        1
                    )';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            
            $this -> log -> debug(
                'Registered affiliation uid='.$body['uid'].' to reflink='.$body['refid'].
                ' uid='.$reflink['uid']
            );
        }
        else
            $this -> log -> debug(
                'Not registered affiliation uid='.$body['uid'].' to reflink='.$body['refid'].
                ' uid='.$reflink['uid'].' because reflink is inactive'
            );
        
        $task = array(
            ':slave_uid' => $body['uid'],
            ':master_uid' => $reflink['uid']
        );
        
        $sql = 'INSERT INTO affiliations(
                    refid,
                    slave_uid,
                    slave_level
                )
                SELECT affiliations.refid,
                       :slave_uid,
                       affiliations.slave_level + 1
                FROM affiliations,
                     reflinks
                WHERE affiliations.refid = reflinks.refid
                AND affiliations.slave_level <= 3
                AND affiliations.slave_uid = :master_uid
                AND reflinks.active = TRUE
                RETURNING affiliations.refid,
                          affiliations.slave_level';
    
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        while($row = $q -> fetch())
            $this -> log -> debug(
                'Registered affiliation uid='.$body['uid'].' to reflink='.$row['refid'].
                ' slave_level='.$row['slave_level']
            );
        
        $this -> pdo -> commit();  
    }
    
    public function countMembers($refid) {
        $result = [];
        
        for($i = 1; $i <= 4; $i++) {
            $task = array(
                ':slave_level' => $i,
                ':refid' => $refid
            );
            
            $sql = 'SELECT COUNT(slave_uid) AS count
                    FROM affiliations
                    WHERE refid = :refid
                    AND slave_level = :slave_level';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            $row = $q -> fetch();
            
            $result[$i] = $row['count'];
        }
        
        return $result;
    }
}

?>