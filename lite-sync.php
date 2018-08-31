<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class LiteSyncPlugin
 * @package Grav\Plugin
 */
class LiteSyncPlugin extends Plugin
{

    private $basePath = './';

    private $sshPath = '.ssh';

    private $sshName = 'id_rsa';
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // var_dump($this->config->get('plugins.lite-sync.git_repo_url'));
        $this->init();
        // Enable the main event we are interested in
        $this->enable([
            'onAdminAfterSave'      => ['onAdminAfterSave', 0]
        ]);
    }

    /**
     * Init plugin conffiguration
     */
    public function init(){
        if(!$this->hasSSHKey()){
            $this->config->set('plugins.lite-sync.git_ssh_key',$this->addSSHKey());
        }else {
            $this->config->set('plugins.lite-sync.git_ssh_key',$this->getSSHKey());
        }
     
        // config remote depot options
        $this->addConfig();
            
        //config user
        $user = 'Grav '.$this->config->get('site.title');
        $cmd = 'config user.name "'.$user.'" 2>&1';
        $this->gitExec($cmd, $out, $res);

        $cmd = 'config user.email "grav@user.com" 2>&1';
        $this->gitExec($cmd, $out1, $res1);

        //config remote repository
        $cmd = 'remote rm distant';
        $this->gitExec($cmd, $out, $res);

        //$host = str_replace(':',':'.$this->config->get('plugins.lite-sync.git_repo_port').':', $this->config->get('plugins.lite-sync.git_remote'));
        $host = $this->config->get('plugins.lite-sync.git_remote');
   
        $cmd = 'remote add distant '.$host;;
        $this->gitExec($cmd, $out, $res);

        // Try to cache distant repository
        $cmd = 'ls-remote distant < yes 2>&1';
        $this->gitExec($cmd, $out, $res);

             
    }

    /**
     * Trigger git hook
     */
    public function onAdminAfterSave($event){
        $date = new \Datetime;

        //checkout on the branch : 
        $branch = $this->config->get('plugins.lite-sync.git_branch');
        $cmd = 'checkout -b '.$branch;
        $this->gitExec($cmd, $out, $res);

        //if branch exist
        if(isset($res) && $res !== 0){
            $cmd = 'checkout '.$branch;
            $this->gitExec($cmd, $out00, $res00);
        }

        //add untracked files - only pages
        $cmd = 'add user/pages/';
        $this->gitExec($cmd, $out, $res);

        //create commit message 
        $commit = 'features(pages):'.$date->format('Y/m/d h:i').' final user modifications';

        //make commit : 
        $cmd = 'commit -m "'.$commit.'" 2>&1';
        $this->gitExec($cmd, $out, $res);

        //push :
        // $cmd = 'push -v distant '.$branch.' < yes  2>&1';
        $cmd = 'push -v distant '.$branch.' 2>&1';
        $this->gitExec($cmd, $out, $res);

    }

    /**
     * Check is ssh key exist
     */
    public function hasSSHKey(){
        if(is_dir(realpath($this->basePath.$this->sshPath))){
            return file_exists(realpath($this->basePath.$this->sshPath.'/'.$this->sshName.'.pub'));
        }
        return false;
    }
    /**
     * add ssh key
     */
    public function addSSHKey(){
        if(is_dir(realpath($this->basePath.$this->sshPath))){
            if(file_exists(realpath($this->basePath.$this->sshPath.'/'.$this->sshName))){
                touch(realpath($this->basePath.$this->sshPath).'/'.$this->sshName);
            }
        } else {
            mkdir(realpath($this->basePath).$this->sshPath);
            touch(realpath($this->basePath.$this->sshPath).'/'.$this->sshName);
        }

        $cmd = 'ssh-keygen -t rsa -b 4096 -N "" -f '.realpath($this->basePath.$this->sshPath).'/'.$this->sshName.'';

        exec($cmd, $out, $res);
        

        if($res === 0){
            $cmd = 'ssh-add -K '.realpath($this->basePath.$this->sshPath.'/'.$this->sshName).'';
            exec($cmd, $out, $res);
            if($res === 0)
                return $this->getSSHKey();
        }
        return false;
    }

    /**
     * get ssh key
     */
    public function getSSHKey(){
        if ($this->hasSSHKey()){
            return file_get_contents(realpath($this->basePath.$this->sshPath.'/'.$this->sshName.'.pub'));
        }
        return false;
    }

    /**
     * check if configfile exist
     */
    public function hasConfig(){
        return file_exists(realpath($this->basePath.$this->sshPath.'/config'));
    }

    /**
     * add Config
     */
    public function addConfig(){
        if(!$this->hasConfig()){
            touch(realpath($this->basePath.$this->sshPath).'/config');
            $data = sprintf("Host %s \nPort %s\n",$this->config->get('plugins.lite-sync.git_repo_url'),$this->config->get('plugins.lite-sync.git_repo_port'));
            file_put_contents(realpath($this->basePath.$this->sshPath.'/config'), $data);
        } else {
            $data = $this->configToArray($this->getConfFile());
            $data['Host'] = $this->config->get('plugins.lite-sync.git_repo_url');
            $data['Port'] = $this->config->get('plugins.lite-sync.git_repo_port');
            file_put_contents(realpath($this->basePath.$this->sshPath.'/config'), $this->arrayToConfig($data));
        }
    
        /*
        Todo: add config file for ssh client
        $cmd = 'config --file '.realpath($this->basePath.$this->sshPath.'/config').' 2>&1';
        $this->gitExec($cmd, $out1, $res1);
        */
        $cmd = 'config core.sshCommand "ssh -i ';
        $cmd .= realpath($this->basePath.$this->sshPath.'/'.$this->sshName.'.pub');
        $cmd .= ' -p ';
        $cmd .= $this->config->get('plugins.lite-sync.git_repo_port');
        $cmd .='" 2>&1';
        $this->gitExec($cmd, $out1, $res1);

        if(isset($res) && $res === 0){
            return true;
        }
        return false;
    }

    /**
     * Exec a git command in project context
     */
    public function gitExec($cmd, &$output, &$result){
        chdir(realpath($this->basePath));
        //$git = "/usr/bin/git -c 'core.sshCommand=ssh -p port'";
        $git = "/usr/bin/git ";

        exec($git.' '.$cmd, $output, $result);

    }

    /**
     * get config file
     */
    public function getConfFile(){
        return file_get_contents(realpath($this->basePath.$this->sshPath.'/config'));
    }

    /**
     * convert config fil into array
     */
    public function configToArray(String $data){
        $tmp = explode("\n", $data);
        $result = [];
        foreach($tmp as $ligne){
            $tp = explode(' ', $ligne);
            if($tp[0] !== '' && null !== $tp[1])
                $result[$tp[0]] = $tp[1];
        }
        return $result;
    }

    /**
     * convert array to config file
     */
    public function arrayToConfig(Array $data){
        $result = '';
        foreach($data as $key => $value){
            $result .= $key.' '.$value."\n";
        }
        return $result;
    }
}
