<?php
/**
 * Created by PhpStorm.
 * User: nitro
 * Date: 10/12/18
 * Time: 09:30
 */

namespace App\Server;


class Server
{

    private $distribuicao;

    protected $repositorio;
    private $ssh;

    public function __construct($ssh = "")
    {
        $this->ssh = $ssh;
    }
    public function repositorio(){
        if(strpos($this->distribuicao,"centos")>0){
            $this->repositorio=" yum ";
        }elseif(strpos($this->distribuicao,"ubuntu")>0){
            $this->repositorio=" apt ";
        }
    }
    protected function getDistribuicao(){

    }
    public function exec($command){
        $distri ="";
        if(!$this->distribuicao){
            $distri = "lsb_release -a;";
        }
        exec($this->ssh."'$distri $command'");
    }
}