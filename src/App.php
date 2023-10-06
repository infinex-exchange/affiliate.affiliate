<?php

require __DIR__.'/Signup.php';

require __DIR__.'/API/ReflinksAPI.php';
require __DIR__.'/API/SettlementsAPI.php';

class App extends Infinex\App\App {
    private $pdo;
    
    private $signup;
    
    private $reflinksApi;
    private $settlementsApi;
    private $rest;
    
    function __construct() {
        parent::__construct('affiliate.affiliate');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> signup = new Signup(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> reflinksApi = new ReflinksAPI(
            $this -> log,
            $this -> pdo
        );
        
        $this -> settlementsApi = new SettlementsAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            REFERENCE_COIN
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> reflinksApi,
                $this -> settlementsApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> signup -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> rest -> stop() -> then(
            function() use($th) {
                return $th -> signup -> stop();
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>