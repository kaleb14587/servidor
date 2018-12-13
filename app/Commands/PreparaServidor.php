<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class PreparaServidor extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'servidor  {ssh_connection} ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';
    /**
     * @var bool
     */
    private $chave_ssh_configurada = false;
    /**
     * @var
     */
    private $ssh;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->ssh = $this->argument("ssh_connection");
        $ssh_array = explode("@", $this->ssh);
        $user = $ssh_array[0];
        $host = $ssh_array[1];
        $this->testaConexao($user);
        do {
            $option = $this->menu('Lindinaldo configurações de servidor para ==> ' . $host, [
                'ssh' => 'Configurar a chave ssh',
                'nfs' => ' NFS ',
                'nginx' => 'instalar e configurar nginx',
            ])->open();
            if ($option == 'ssh') {
                $this->configuraChaveSsh();
            } elseif ($option == 'nfs') {
                $this->nfsServer();
            } elseif ($option == 'nginx') {
                $this->instalaNginx();
            }
        } while (in_array($option, ['ssh', 'nfs', 'nginx']));
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * @param $user
     */
    private function testaConexao($user)
    {
        exec("ssh " . $this->ssh . "'echo 1;cat /home/$user/.ssh/authorized_keys'", $opt, $ret);

        if ($ret) {
            $this->error($ret);
            foreach ($opt as $o)
                $this->error($o);
        } else {
            if ($opt[0] == 1) {
                $this->info('Conexão estabelecida');
            }
            if ($this->chave_ssh_configurada) {
                $this->info('Chave configurada');
                return;
            }

            $chave = $this->getChavessh();
            $chave = explode(' ', $chave);
            $chave = $chave[1];

            foreach ($opt as $item) {
                strpos($item, $chave);
                if (strpos($item, $chave) > 0) {
                    $chave = null;
                    $this->chave_ssh_configurada = true;
                    $this->info('Chave configurada');
                    break;
                }
            }
            if ($chave != null) {
                $this->info('Chave não esta configurada');
            }
        }

    }
    private function getCurrentUser(){
        exec('echo $USER',$opt,$ret);
        if(!$ret){
          return  end($opt);
        }
        return "~";
    }
    /**
     * @return bool|string
     */
    private function getChavessh()
    {
        $user = $this->getCurrentUser();
        if (file_exists("/home/$user/.ssh/id_rsa.pub")) {

            $chave = file_get_contents("/home/$user/.ssh/id_rsa.pub");
        } else {
            $this->error("chave nao existe, configure-a");
            exec("sh-keygen");
            $chave = file_get_contents("/home/$user/.ssh/id_rsa.pub");
        }
        return $chave;
    }

    /**
     * @return void
     */
    private function configuraChaveSsh()
    {
        if ($this->chave_ssh_configurada) {
            $this->ask('Chave ja configurada');
            return;
        }
        $this->comment("Configurando chave ssh no novo servidor");
        $chave = $this->getChavessh();
        $this->comment($chave);
        $this->comment("validando arquivo de chaves");
        exec("ssh $this->ssh 'ls ~/.ssh/authorized_keys'", $opt, $ret);
        if ($ret) {
            $this->error("necessario criar o arquivo '~/.ssh/authorized_keys'");
            $this->ask('continue [ENTER]');
            return;
        }
        $this->comment("importando chave de autorização");
        exec("ssh $this->ssh 'sudo echo \"$chave\">>~/.ssh/authorized_keys' ", $opt, $ret);
        if ($ret) {
            $this->error("não foi possivel configurar a chave ssh no servidor");
            $this->ask('continue [ENTER]');
            return;
        }

        $this->alert("Chave ssh configurada com sucesso!!!");
        $this->ask('continue [ENTER]');
        return;
    }

    /**
     * @return void
     */
    private function nfsServer()
    {
        do {
            $option = $this->menu('Nfs ', [
                'ip' => 'Adicionar regra para um ip',
                'install' => 'instalar nfs',
                'lista' => 'lista de regras',
            ])->open();
            if ($option == 'ip') {
                $this->registrarServidor();
            } elseif ($option == 'install') {
                $this->instalarNfsServer();
            }elseif($option=='lista'){
                $this->listarRegrasNfs();
                $this->ask('pressione [ENTER] para sair');
            }
        } while (in_array($option, ['ip', 'install']));

    }

    /**
     *
     */
    private function atualizaPacotes()
    {
        $this->alert("atualizando pacotes do destino");
        exec("ssh $this->ssh 'sudo apt update && exit '");
    }

    /**
     *
     */
    private function instalaNginx()
    {
        if ($this->confirm("configurar o nginx?", true)) {
            $this->atualizaPacotes();
            exec("ssh $this->ssh 'nginx -v '", $opt, $err);
            if ($err) {
                $this->alert("instalando nginx");
                exec("ssh $this->ssh 'sudo apt-get install nginx -y '", $opt, $err);
                if ($err) {
                    $this->ask('Ocorreram muitos erros ao tentar instalar o nginx tente instalar manualmente');
                    return;
                }
            }
            exec("ssh $this->ssh 'sudo rm -rf /etc/nginx/sites-enabled/default'", $opt, $err);
            $host = $this->ask("qual o host padrao ? ");
            $this->arquivoConfNginx($host,"padrao");
            $host = $this->ask("qual o host padrao da revenda ? ");
            $this->arquivoConfNginx($host,"revenda","/landings/revenda");

            $this->ask('continue [ENTER]');
        }
    }
    private function arquivoConfNginx($host,$nomeconf,$pasta="/landings"){
        $this->validaPastaCompartilhadaEcria($pasta);
        $conf = "
            server {
                    listen 80 ;
            
                    root $pasta;
                    index index.html;
            
                    server_name $host;
            
            }";
        $this->alert("Configurando nginx host padrao");
        exec("ssh $this->ssh 'sudo cat /etc/nginx/sites-enabled/$nomeconf'", $opt, $err);
        if ($err) {
            exec("ssh $this->ssh 'sudo touch /etc/nginx/sites-enabled/$nomeconf'", $opt, $ret);
            if ($ret) {
                $this->error("Erro ao tentar gerar o arquivo de configuração padrao");
                $this->ask('continue [ENTER]');
                return;
            }

        }
        exec("ssh $this->ssh 'sudo chmod 777 /etc/nginx/sites-enabled/$nomeconf'");
        exec("ssh $this->ssh 'sudo echo \"$conf\">>/etc/nginx/sites-enabled/$nomeconf'", $opt, $err);
        if ($err) {
            $this->error("Nao foi possivel configurar o arquivo /etc/nginx/sites-enabled/$nomeconf");
            $this->alert("ssh $this->ssh 'sudo echo \"$conf\">>/etc/nginx/sites-enabled/$nomeconf'");
            $this->ask('continue [ENTER]');

        } else {
            exec("ssh $this->ssh 'sudo echo \"<html><body>servidor configurado pelo lindinaldo</body></html>\">/landings/index.html'", $opt, $err);
            $this->info("reiniciando nginx");
            exec("ssh $this->ssh 'sudo service nginx reload'", $opt, $ret);
            if ($ret) {
                $this->error("ocorreu algum erro ao tentar configurar o nginx,Faca manualmente");
                $this->ask('continue [ENTER]');

            } else {
                $this->alert("nginx configurado com sucesso!!!");
                $this->ask('continue [ENTER]');

            }

        }
    }
    /**
     * @return bool
     */
    private function reiniciarNfs()
    {
        $this->comment("Reiniciando servico ");
        exec("ssh  $this->ssh 'sudo service nfs-kernel-server restart'", $opt, $ret);
        if (!$ret) {
            $this->alert("Nfs configurado com sucesso!!!");
            return true;
        } else {
            $this->error('Ocorreu algum erro');
            return false;
        }
    }
    private function listarRegrasNfs(){
        exec("ssh $this->ssh 'cat /etc/exports'", $opt, $ret);
        $tabela = [];
        foreach ($opt as $l) {
            $linha = trim($l);
            if (strpos($linha, "#") === 0) continue;

            $item = explode(" ", $linha);
            $tabela[] = $item;
        }

        $this->alert("Regras ja criadas !");
        $this->table(["pasta", "ip(regras)"], $tabela);
        return $tabela;
    }
    /**
     *
     */
    private function registrarServidor()
    {
        if (!$this->reiniciarNfs()) {
            $this->error("Servidor esta com erro no nfs ");
            $this->ask("Precione [ENTER] para continuar !");
            return;
        }
        exec("ssh $this->ssh 'cat /etc/exports'", $opt, $ret);
        if (!$ret) {
            $bkp = "/etc/exports_rolback";
            exec("ssh $this->ssh 'sudo cp /etc/exports $bkp'", $opt, $ret);
            $tabela = $this->listarRegrasNfs();
            $this->comment("OBS.: Não sera possivel duplicar as regras ja criadas neste servidor");
            do {
                $ip = $this->ask('Digite o host ou o ip do Servidor que tera permissao para acessar o nfs');
                foreach ($tabela as $regra) {
                    if (strpos($regra[1], $ip) > -1) {
                        $this->error("Ip ou host ja cadastrado ");
                        $ip = "";
                        break;
                    }
                }
            } while ($ip == "");

            $this->validaPastaCompartilhadaEcria("/landings");
            $this->comment("Criando a regra de compartilhamento");
            exec("ssh $this->ssh 'sudo chmod 777 /etc/exports'");
            exec("ssh  $this->ssh 'sudo echo \"/landings $ip(rw,no_root_squash,sync)\">>/etc/exports '");
            if (!$this->reiniciarNfs()) {
                $this->error("Erro ao reiniciar");
                $this->error("Rollback sendo iniciado");
                exec("ssh $this->ssh 'sudo echo cat $bkp >/etc/exports'", $opt, $ret);
                $this->info("rolback finalizado");
                $this->ask("Precione [ENTER] para continuar !");
                return;
            }
            $this->info("Configurado com sucesso!");
            $this->ask("Precione [ENTER] para continuar !");
        }
    }

    /**
     *
     */
    private function validaPastaCompartilhadaEcria($pasta)
    {
        exec("ssh $this->ssh 'll $pasta '", $opt, $ret);
        if ($ret) {
            $this->comment("Criando a pasta");
            exec("ssh $this->ssh 'sudo mkdir $pasta '");
            exec("ssh $this->ssh 'sudo chmod 777 $pasta '");
        }

    }

    private function instalarNfsServer()
    {
        do {
            exec("ssh $this->ssh 'sudo apt-get install nfs-kernel-server -y'", $opt, $ret);

            if ($ret) {
                $ret = $this->confirm('executar novamente?');
                $salvo = false;
            } else {
                $salvo = true;
            }

        } while ($ret);
        if (!$salvo)
            $this->ask("Nfs não foi instalado com sucesso!!");
    }
}
