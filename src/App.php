<?php

require __DIR__.'/Reflinks.php';
require __DIR__.'/Settlements.php';
require __DIR__.'/Affiliations.php';
require __DIR__.'/Rewards.php';

require __DIR__.'/API/ReflinksAPI.php';
require __DIR__.'/API/SettlementsAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $reflinks;
    private $settlements;
    private $affiliations;
    private $rewards;
    
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
        
        $this -> reflinks = new Reflinks(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> settlements = new Settlements(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> reflinks,
            REFERENCE_COIN
        );
        
        $this -> affiliations = new Affiliations(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> reflinks
        );
        $this -> reflinks -> setAffiliations($this -> affiliations);
        
        $this -> rewards = new Rewards(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> settlements
        );
        
        $this -> reflinksApi = new ReflinksAPI(
            $this -> log,
            $this -> reflinks
        );
        
        $this -> settlementsApi = new SettlementsAPI(
            $this -> log,
            $this -> amqp,
            $this -> settlements,
            $this -> rewards
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
                return $th -> reflinks -> start();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> settlements -> start(),
                    $th -> affiliations -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> rewards -> start();
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
                return Promise\all([
                    $th -> settlements -> stop(),
                    $th -> affiliations -> stop()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> rewards -> stop();
            }
        ) -> then(
            function() use($th) {
                return $th -> reflinks -> stop();
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