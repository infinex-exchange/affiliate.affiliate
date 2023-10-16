<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use React\Promise;

class Reflinks {
    private $log;
    private $amqp;
    private $pdo;
    private $affiliations;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized reflinks manager');
    }
    
    public function setAffiliations($affiliations) {
        $this -> affiliations = $affiliations;
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
                $th -> log -> info('Started reflinks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start reflinks manager: '.((string) $e));
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
                $th -> log -> info('Stopped reflinks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop reflinks manager: '.((string) $e));
            }
        );
    }
    
    public function getReflinks($body) {
        if(isset($body['uid']) && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(isset($body['active']) && !is_bool($body['active']))
            throw new Error('VALIDATION_ERROR', 'active');
        
        $pag = new Pagination\Offset(50, 500, $body);
        
        $task = array();
        
        $sql = 'SELECT refid,
                       uid,
                       description,
                       active
                FROM reflinks
                WHERE 1=1';
        
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid = :uid';
        }
        
        if(isset($body['active'])) {
            $task[':active'] = $body['active'] ? 1 : 0;
            $sql .= ' AND active = :active';
        }
        
        $sql .= ' ORDER BY refid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $reflinks = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $reflinks[] = $this -> rtrReflink($row);
        }
        
        return [
            'reflinks' => $reflinks,
            'more' => $pag -> more
        ];
    }
    
    public function getReflink($body) {
        if(!isset($body['refid']))
            throw new Error('MISSING_DATA', 'refid', 400);
        
        if(!validateId($body['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
        
        $task = array(
            ':refid' => $body['refid']
        );
        
        $sql = 'SELECT refid,
                       uid,
                       description,
                       active
                FROM reflinks
                WHERE refid = :refid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$body['refid'].' not found', 404);
        
        return $this -> rtrReflink($row);
    }
    
    public function deleteReflink($body) {
        if(!isset($body['refid']))
            throw new Error('MISSING_DATA', 'refid', 400);
        
        if(!validateId($body['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
    
        $task = array(
            ':refid' => $body['refid']
        );
        
        $sql = 'UPDATE reflinks
                SET active = FALSE
                WHERE refid = :refid
                AND active = TRUE
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$body['refid'].' not found', 404);
    }
    
    public function createReflink($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!$this -> validateReflinkDescription($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        
        $this -> pdo -> beginTransaction();
    
        // Check reflink with this name already exists
        $task = array(
            ':uid' => $body['uid'],
            ':description' => $body['description']
        );
        
        $sql = 'SELECT 1
                FROM reflinks
                WHERE uid = :uid
                AND description = :description
                AND active = TRUE
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row) {
            $this -> pdo -> rollBack();
            throw new Error('CONFLICT', 'Reflink with this name already exists', 409);
        }
        
        // Insert reflink
        $sql = "INSERT INTO reflinks(
                    uid,
                    description
                ) VALUES (
                    :uid,
                    :description
                )
                RETURNING refid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        $this -> pdo -> commit();
        
        return $row['refid'];
    }
    
    public function editReflink($body) {
        if(!isset($body['refid']))
            throw new Error('MISSING_DATA', 'refid', 400);
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!validateId($body['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
        if(!$this -> validateReflinkDescription($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
        
        $this -> pdo -> beginTransaction();
        
        // Get uid
        $task = array(
            ':refid' => $body['refid']
        );
        
        $sql = 'SELECT uid
                FROM reflinks
                WHERE refid = :refid
                AND active = TRUE
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Reflink '.$body['refid'].' not found', 404);
        }
            
        $uid = $row['uid'];
        
        // Check reflink with this name already exists
        $task = array(
            ':uid' => $uid,
            ':description' => $body['description'],
            ':refid' => $body['refid']
        );
        
        $sql = 'SELECT 1
                FROM reflinks
                WHERE uid = :uid
                AND description = :description
                AND refid != :refid
                AND active = TRUE
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row) {
            $this -> pdo -> rollBack();
            throw new Error('CONFLICT', 'Reflink with this name already exists', 409);
        }
        
        // Update reflink
        $sql = 'UPDATE reflinks
                SET description = :description
                WHERE refid = :refid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
    }
    
    private function validateReflinkDescription($desc) {
        return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $desc);
    }
    
    private function rtrReflink($row) {
        return [
            'refid' => $row['refid'],
            'uid' => $row['uid'],
            'description' => $row['description'],
            'members' => $this -> affiliations -> countMembers($row['refid'])
        ];
    }
    
    
}

?>