<?php

use Infinex\Exceptions\Error;

class ReflinksAPI {
    private $log;
    private $reflinks;
    
    function __construct($log, $reflinks) {
        $this -> log = $log;
        $this -> reflinks = $reflinks;

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
            
        $resp = $this -> reflinks -> getReflinks([
            'uid' => $auth['uid'],
            'active' => true,
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        foreach($resp['reflinks'] as $k => $v)
            $resp['reflinks'][$k] = $this -> ptpReflink($v);
        
        return $resp;
    }
    
    public function getReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> ptpReflink(
            $this -> reflinks -> getReflink([
                'uid' => $auth['uid'],
                'active' => true,
                'refid' => $path['refid']
            ])
        );
    }
    
    public function editReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $this -> reflinks -> editReflink([
            'uid' => $auth['uid'],
            'refid' => $path['refid'],
            'description' => @$body['description']
        ]);
    }
    
    public function deleteReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
    
        $this -> reflinks -> deleteReflink([
            'uid' => $auth['uid'],
            'refid' => $path['refid']
        ]);
    }
    
    public function addReflink($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $refid = $this -> reflinks -> createReflink([
            'uid' => $auth['uid'],
            'description' => @$body['description']
        ]);
        
        return [
            'refid' => $refid
        ];
    }
    
    private function ptpReflink($record) {
        return [
            'refid' => $record['refid'],
            'description' => $record['description'],
            'members' => $record['members']
        ];
    }
}

?>