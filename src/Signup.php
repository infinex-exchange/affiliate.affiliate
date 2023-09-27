<?php

class Signup {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized signup with reflink handler');
    }
    
    public function start() {
        $th = $this;
        
        return $this -> amqp -> sub(
            'registerUser',
            function($body) use($th) {
                return $th -> setupAccount($body);
            },
            'affiliate_signup',
            true,
            [
                'affiliation' => true
            ]
        ) -> then(
            function() use($th) {
                $th -> log -> info('Started signup with reflink handler');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start signup with reflink handler: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        return $this -> amqp -> unsub('affiliate_signup') -> then(
            function() use ($th) {
                $th -> log -> info('Stopped signup with reflink handler');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop signup with reflink handler: '.((string) $e));
            }
        );
    }
    
    public function setupAccount($body) {
        $task = array(
            ':refid' => $body['refid']
        );
        
        $sql = 'SELECT uid,
                       active
                FROM reflinks
                WHERE refid = :refid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row) {
            $this -> pdo -> beginTransaction();
            
            if($row['active']) {
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
                    ' uid='.$row['uid']
                );
            }
            else
                $this -> log -> debug(
                    'Not registered affiliation uid='.$body['uid'].' to reflink='.$body['refid'].
                    ' uid='.$row['uid'].' because reflink is inactive'
                );
            
            $task = array(
                ':slave_uid' => $body['uid'],
                ':master_uid' => $row['uid']
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
        else
            $this -> log -> debug(
                'Not registered affiliation uid='.$body['uid'].' to reflink='.$body['refid'].
                ' because not found refid'
            );
    }
}

?>