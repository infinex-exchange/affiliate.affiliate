<?php

require_once __DIR__.'/validate.php';

use Infinex\Exceptions\Error;
use Infinex\Pagination;

class ReflinksAPI {
    private $log;
    private $pdo;
    
    function __construct($log, $pdo) {
        $this -> log = $log;
        $this -> pdo = $pdo;

        $this -> log -> debug('Initialized reflinks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/reflinks', [$this, 'getAllReflinks']);
        $rc -> get('/reflinks/{refid}', [$this, 'getReflink']);
        $rc -> patch('/reflinks/{refid}', [$this, 'editReflink']);
        $rc -> delete('/reflinks/{refid}', [$this, 'deleteReflink']);
        $rc -> post('/reflinks', [$this, 'addReflink']);
    }
    
    public function getAllReflinks($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
            
        $pag = new Pagination\Offset(50, 500, $query);
        
        $task = array(
            ':uid' => $auth['uid']
        );
        
        $sql = "SELECT refid,
                       description
                FROM reflinks
                WHERE uid = :uid
                AND active = TRUE
                ORDER BY refid ASC"
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $reflinks = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            
            $reflinks[] = [
                'refid' => $row['refid'],
                'description' => $row['description'],
                'members' => $this -> getMembers($row['refid'])
            ];
        }
        
        return [
            'reflinks' => $reflinks,
            'more' => $pag -> more
        ];
    }
    
    public function getReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!validateRefid($path['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
        
        $task = array(
            ':uid' => $auth['uid'],
            ':refid' => $path['refid']
        );
        
        $sql = 'SELECT description
                FROM reflinks
                WHERE uid = :uid
                AND refid = :refid
                AND active = TRUE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$path['refid'].' not found', 404);
        
        return [
            'refid' => $path['refid'],
            'description' => $row['description'],
            'members' => $this -> getMembers($path['refid'])
        ];
    }
    
    public function editReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!validateRefid($path['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
        if(!$this -> validateReflinkDescription($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
    
        // Check reflink with this name already exists
        $task = array(
            ':uid' => $auth['uid'],
            ':refid' => $path['refid'],
            ':description' => $body['description']
        );
        
        $sql = 'SELECT 1
                FROM reflinks
                WHERE uid = :uid
                AND description = :description
                AND refid != :refid
                AND active = TRUE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch(PDO::FETCH_ASSOC);
        
        if($row)
            throw new Error('ALREADY_EXISTS', 'Reflink with this name already exists', 409);
        
        // Update reflink
        $task = array(
            ':uid' => $auth['uid'],
            ':refid' => $path['refid'],
            ':description' => $body['description']
        );
        
        $sql = 'UPDATE reflinks
                SET description = :description
                WHERE uid = :uid
                AND refid = :refid
                AND active = TRUE
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$path['refid'].' not found', 404);
    }
    
    public function deleteReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!validateRefid($path['refid']))
            throw new Error('VALIDATION_ERROR', 'refid', 400);
    
        $task = array(
            ':uid' => $auth['uid'],
            ':refid' => $path['refid']
        );
        
        $sql = 'UPDATE reflinks
                SET active = FALSE
                WHERE uid = :uid
                AND refid = :refid
                AND active = TRUE
                RETURNING 1';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Reflink '.$path['refid'].' not found', 404);
    }
    
    public function addReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(!isset($body['description']))
            throw new Error('MISSING_DATA', 'description', 400);
        
        if(!$this -> validateReflinkDescription($body['description']))
            throw new Error('VALIDATION_ERROR', 'description', 400);
    
        // Check reflink with this name already exists
        $task = array(
            ':uid' => $auth['uid'],
            ':description' => $body['description']
        );
        
        $sql = 'SELECT refid
            FROM reflinks
            WHERE uid = :uid
            AND description = :description
            AND active = TRUE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if($row)
            throw new Error('ALREADY_EXISTS', 'Reflink with this name already exists', 409);
        
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
        
        return [
            'refid' => $row['refid'],
        ];
    }
    
    private function validateReflinkDescription($desc) {
        return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $desc);
    }
    
    private function getMembers($refid) {
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